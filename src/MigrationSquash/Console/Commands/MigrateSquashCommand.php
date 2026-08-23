<?php

namespace MigrationSquash\Console\Commands;

use Illuminate\Console\Command;
use MigrationSquash\Archiving\MigrationArchiver;
use MigrationSquash\Discovery\MigrationScanner;
use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Guards\DataSeedDetector;
use MigrationSquash\Guards\RawSqlDetector;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;
use MigrationSquash\Verification\SchemaComparator;
use MigrationSquash\Verification\SchemaDiff;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:squash')]
class MigrateSquashCommand extends Command
{
    protected $signature = '
        migrate:squash
                    {--dry-run : Generate and verify without archiving old migrations}
                    {--check : Only check for guarded migrations, don\'t run squash}
                    {--driver= : Force sandbox database driver (sqlite or mysql)}
                    {--table=* : Squash only specific tables (can be repeated)}
    ';

    protected $description = 'Combine multiple migration files into a single consolidated migration per table';

    /**
     * The sandbox connection name, set during setupSandboxConnection().
     */
    protected ?string $sandboxConnectionName = null;

    /**
     * Migration files excluded by guards (raw SQL or data seeding).
     *
     * @var array<string, string>
     */
    protected array $guardedFiles = [];

    /**
     * Unix timestamp used as the base for generated migration filenames.
     *
     * Squashed migrations must sort BEFORE any migration left behind in
     * database/migrations (guarded ones keep their original timestamps),
     * because Laravel runs migrations in filename order. Using "now" would
     * put the squashed files last and break a guarded Schema::table() that
     * targets a table the squashed files create.
     */
    protected ?int $baseTimestamp = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Laravel Migration Squasher');
        $this->newLine();

        try {
            // Step 1: Scan migrations
            $migrations = $this->step1_ScanMigrations();

            if ($migrations === null) {
                return Command::FAILURE;
            }

            $migrationFiles = array_column($migrations, 'file');

            // Generated files must sort before every original migration,
            // including any that guards leave behind.
            $this->baseTimestamp = $this->resolveBaseTimestamp($migrations);

            // Step 2: Check for guarded migrations
            if ($this->option('check')) {
                return $this->handleCheckMode($migrationFiles);
            }

            // Step 2b: Filter out guarded migrations (exclude, don't fail)
            $migrationFiles = $this->step2_FilterGuardedMigrations($migrationFiles);

            if (empty($migrationFiles)) {
                $this->warn('⚠️ All migrations are guarded. Nothing to squash.');

                return Command::FAILURE;
            }

            // Step 3: Setup sandbox connection
            $this->sandboxConnectionName = $this->step3_SetupSandbox();

            // Step 4: Run original migrations in sandbox
            $this->step4_RunOriginalInSandbox($migrationFiles);

            // Step 5: Introspect schema
            $snapshotBefore = $this->step5_IntrospectSchema();

            // Step 6: Generate squashed migrations
            $generatedMigrations = $this->step6_GenerateSquashedMigrations($snapshotBefore);

            // Step 7: Verify generated schema
            $verificationResult = $this->step7_VerifyGeneratedSchema(
                $snapshotBefore,
                $generatedMigrations,
            );

            // Step 8: Report and optionally archive
            return $this->step8_ReportAndArchive(
                $verificationResult,
                $migrationFiles,
                $generatedMigrations,
            );
        } catch (\Throwable $e) {
            $this->error("❌ {$e->getMessage()}");
            $this->error("Stack trace:\n{$e->getTraceAsString()}");

            return Command::FAILURE;
        } finally {
            // Always cleanup sandbox
            if ($this->sandboxConnectionName !== null) {
                SandboxConnectionFactory::destroy($this->sandboxConnectionName);
            }
        }
    }

    /**
     * Step 1: Discover migrations and optionally filter by table name.
     *
     * @return array<int, array{file: string, timestamp: string, name: string}>|null
     */
    protected function step1_ScanMigrations(): ?array
    {
        $this->info('📂 Step 1: Scanning migrations...');

        $scanner = new MigrationScanner;
        $migrations = $scanner->scan();

        if (empty($migrations)) {
            $this->warn('⚠️ No migrations found in database/migrations/');

            return null;
        }

        $this->line('   Found '.count($migrations).' migration files');

        // Filter by specific tables if --table option is provided
        $tablesToSquash = $this->option('table');

        if (! empty($tablesToSquash)) {
            $filtered = array_filter($migrations, function ($m) use ($tablesToSquash) {
                foreach ($tablesToSquash as $table) {
                    if (str_contains(strtolower($m['file']), strtolower($table))) {
                        return true;
                    }
                }

                return false;
            });
            $migrations = array_values($filtered);
            $this->line('   Filtered to '.count($migrations).' migrations for specified tables');
        }

        return $migrations;
    }

    /**
     * Resolve the earliest original migration timestamp as a unix timestamp.
     *
     * Generated filenames are laid out ending just before this value, so every
     * generated file sorts ahead of every original migration that stays behind.
     * Falls back to the current time when no timestamp can be parsed.
     *
     * @param  array<int, array{file: string, timestamp: string, name: string}>  $migrations
     */
    protected function resolveBaseTimestamp(array $migrations): int
    {
        $earliest = null;

        foreach ($migrations as $migration) {
            $parsed = \DateTimeImmutable::createFromFormat(
                'Y_m_d_His',
                $migration['timestamp'],
                new \DateTimeZone('UTC'),
            );

            if ($parsed === false) {
                continue;
            }

            $unix = $parsed->getTimestamp();

            if ($earliest === null || $unix < $earliest) {
                $earliest = $unix;
            }
        }

        return $earliest ?? time();
    }

    /**
     * Handle check mode — just detect problematic migrations.
     *
     * @param  array<string>  $migrationFiles
     */
    protected function handleCheckMode(array $migrationFiles): int
    {
        $this->info('🔒 Running in check mode...');

        $rawSqlDetector = new RawSqlDetector;
        $dataSeedDetector = new DataSeedDetector;

        // Check mode is a diagnostic, so it reports regardless of whether the
        // guards are switched on for the squash itself.
        $rawSqlIssues = $rawSqlDetector->detectAll($migrationFiles);
        $dataSeedIssues = $dataSeedDetector->detectAll($migrationFiles);

        $allIssues = array_merge($rawSqlIssues, $dataSeedIssues);

        if (empty($allIssues)) {
            $this->info('✅ All migrations are safe to squash');

            return Command::SUCCESS;
        }

        foreach ([$rawSqlDetector, $dataSeedDetector] as $detector) {
            if (! $detector->isEnabled()) {
                $this->warn('ℹ️ '.class_basename($detector).' is disabled in config; its findings below are informational only.');
            }
        }

        $this->error('❌ Found '.count($allIssues).' migration(s) that cannot be squashed:');

        foreach ($allIssues as $file => $reason) {
            $relativePath = str_replace(database_path('migrations/').'/', '', $file);
            $this->line("\n  • {$relativePath}");
            $this->line("    Reason: {$reason}", 'yellow');
        }

        $this->newLine();
        $this->warn('These migrations will be excluded from squash.');

        return Command::FAILURE;
    }

    /**
     * Step 2b: Filter out guarded migrations and report them.
     *
     * Guarded migrations are excluded from squash but not archived.
     *
     * @param  array<string>  $migrationFiles
     * @return array<string> Safe migration files
     */
    protected function step2_FilterGuardedMigrations(array $migrationFiles): array
    {
        $rawSqlDetector = new RawSqlDetector;
        $dataSeedDetector = new DataSeedDetector;

        $rawSqlIssues = $rawSqlDetector->detect($migrationFiles);
        $dataSeedIssues = $dataSeedDetector->detect($migrationFiles);

        $detected = array_merge($rawSqlIssues, $dataSeedIssues);

        // guards.warn_on_detection: report the findings but still squash them.
        $warnOnly = (bool) config('migrationsquash.guards.warn_on_detection', false);

        if (! empty($detected)) {
            $verb = $warnOnly ? 'flagged but still squashed' : 'excluded from squash';
            $this->warn('⚠️ '.count($detected)." migration(s) {$verb}:");

            foreach ($detected as $file => $reason) {
                $this->line('   • '.basename($file).": {$reason}");
            }

            if ($warnOnly) {
                $this->line('   guards.warn_on_detection is on, so these are NOT excluded.');
                $this->line('   Verification still has to pass, so a migration this package');
                $this->line('   cannot reproduce will fail the run rather than pass silently.');
            }

            $this->newLine();
        }

        if ($warnOnly) {
            $this->guardedFiles = [];

            return $migrationFiles;
        }

        $this->guardedFiles = $detected;

        return array_values(array_diff($migrationFiles, array_keys($this->guardedFiles)));
    }

    /**
     * Step 3: Set up sandbox connection.
     *
     * @return string Connection name
     */
    protected function step3_SetupSandbox(): string
    {
        $this->info('🧪 Step 2: Setting up sandbox connection...');

        $forceDriver = $this->option('driver');

        if ($forceDriver !== null) {
            if (! in_array($forceDriver, ['sqlite', 'mysql'])) {
                throw new \InvalidArgumentException(
                    "Invalid --driver value '{$forceDriver}'. Must be 'sqlite' or 'mysql'."
                );
            }

            $connectionName = SandboxConnectionFactory::create($forceDriver);
        } else {
            $connectionName = SandboxConnectionFactory::create('sqlite');
            $this->info('ℹ️ Using SQLite in-memory sandbox');
        }

        $this->line("   Connection name: {$connectionName}");

        return $connectionName;
    }

    /**
     * Step 4: Run original migrations in sandbox.
     *
     * @param  array<string>  $migrationFiles
     */
    protected function step4_RunOriginalInSandbox(array $migrationFiles): void
    {
        $this->info('⚡ Step 3: Running original migrations in sandbox...');

        $runner = new SandboxRunner($this->sandboxConnectionName);
        $runner->fresh();

        $success = $runner->run($migrationFiles);

        if ($success) {
            $this->info('✅ Original migrations executed successfully');
        } else {
            throw new \RuntimeException('Failed to execute original migrations in sandbox');
        }
    }

    /**
     * Step 5: Introspect schema after running migrations.
     *
     * @return array{tables: array<string, array<string, mixed>>}
     */
    protected function step5_IntrospectSchema(): array
    {
        $this->info('📋 Step 4: Introspecting schema...');

        $introspector = new SchemaIntrospector($this->sandboxConnectionName);
        $snapshot = $introspector->getSnapshot();

        $this->line('   Discovered '.count($snapshot['tables']).' tables');

        return $snapshot;
    }

    /**
     * Step 6: Generate squashed migrations (tables without FK, plus one FK file).
     *
     * @return array<string, array{filename: string, content: string, table: string}>
     */
    protected function step6_GenerateSquashedMigrations(array $snapshot): array
    {
        $this->info('🏗️ Step 5: Generating squashed migrations...');

        $generator = new SquashedMigrationGenerator;
        $generatedMigrations = [];
        $allTables = [];

        // Lay the generated files out in the seconds immediately BEFORE the
        // earliest original migration, so they always run first. Reserve one
        // slot per table plus one for the foreign-key file.
        $slots = count($snapshot['tables']) + 1;
        $startTimestamp = ($this->baseTimestamp ?? time()) - $slots;
        $i = 0;

        foreach ($snapshot['tables'] as $tableName => $tableData) {
            $timestamp = date('Y_m_d_His', $startTimestamp + $i);
            $filename = "{$timestamp}_create_{$tableName}_table.php";

            $table = $this->rebuildTableFromSnapshot($tableName, $tableData);
            $allTables[$tableName] = $table;

            $migrationContent = $generator->generate($table, $filename);

            $generatedMigrations[$tableName] = [
                'filename' => $filename,
                'content' => $migrationContent,
                'table' => $tableName,
            ];

            $this->line("   Generated: {$filename}");
            $i++;
        }

        // Generate separate FK migration (D-3)
        $fkContent = $generator->generateForeignKeyMigration($allTables);

        if ($fkContent !== null) {
            // The reserved last slot, still before the earliest original.
            $fkTimestamp = date('Y_m_d_His', $startTimestamp + $slots - 1);
            $fkFilename = "{$fkTimestamp}_add_foreign_keys.php";

            $generatedMigrations['__foreign_keys__'] = [
                'filename' => $fkFilename,
                'content' => $fkContent,
                'table' => '__foreign_keys__',
            ];

            $this->line("   Generated: {$fkFilename}");
        }

        return $generatedMigrations;
    }

    /**
     * Rebuild a Table object from a serialized snapshot.
     */
    protected function rebuildTableFromSnapshot(string $tableName, array $tableData): Table
    {
        $columns = array_map(fn ($col) => new Column(
            name: $col['name'],
            type: $col['type'],
            nullable: $col['nullable'],
            default: $col['default'],
            length: $col['length'] ?? null,
            unsigned: $col['unsigned'] ?? false,
            collation: $col['collation'] ?? null,
            autoIncrement: (bool) ($col['auto_increment'] ?? false),
            allowedValues: $col['allowed_values'] ?? [],
        ), $tableData['columns'] ?? []);

        $indexes = array_map(fn ($idx) => new Index(
            name: $idx['name'],
            type: $idx['type'],
            columns: $idx['columns'],
            options: $idx['options'] ?? null,
        ), $tableData['indexes'] ?? []);

        $foreignKeys = array_map(fn ($fk) => new ForeignKey(
            name: $fk['name'],
            column: $fk['column'],
            referencedTable: $fk['referenced_table'],
            referencedColumn: $fk['referenced_column'],
            onUpdate: $fk['on_update'] ?? null,
            onDelete: $fk['on_delete'] ?? null,
        ), $tableData['foreign_keys'] ?? []);

        $table = new Table(name: $tableName);
        $table->addColumns($columns);

        foreach ($indexes as $index) {
            $table->addIndex($index);
        }

        foreach ($foreignKeys as $fk) {
            $table->addForeignKey($fk);
        }

        return $table;
    }

    /**
     * Step 7: Verify generated schema matches original.
     */
    protected function step7_VerifyGeneratedSchema(
        array $originalSnapshot,
        array $generatedMigrations,
    ): SchemaDiff {
        $this->info('✓ Step 6: Verifying generated schema...');

        // Fresh sandbox
        $runner = new SandboxRunner($this->sandboxConnectionName);
        $runner->fresh();

        // Write generated migrations to temp dir and run them
        $tempDir = sys_get_temp_dir().'/squash-verify-'.uniqid();
        mkdir($tempDir, 0755, true);

        try {
            foreach ($generatedMigrations as $migration) {
                $filePath = $tempDir.'/'.$migration['filename'];
                file_put_contents($filePath, $migration['content']);
            }

            $runner->run(glob($tempDir.'/*.php'));

            // Introspect again
            $introspector = new SchemaIntrospector($this->sandboxConnectionName);
            $snapshotAfter = $introspector->getSnapshot();

            // Compare
            $comparator = new SchemaComparator;
            $diff = $comparator->compare($originalSnapshot, $snapshotAfter);

            if ($diff->isEmpty()) {
                $this->info('✅ Schema verification passed!');
            } else {
                $this->error('❌ Schema verification failed!');
                $this->line($diff->formatMessage());
            }

            return $diff;
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * Step 8: Report results and optionally archive.
     */
    protected function step8_ReportAndArchive(
        SchemaDiff $diff,
        array $migrationFiles,
        array $generatedMigrations,
    ): int {
        if (! $diff->isEmpty()) {
            $this->newLine();
            $this->warn('Squashing ABORTED to prevent schema mismatch.');

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('📊 Summary:');
        $this->line('   • '.count($migrationFiles).' original migration files');
        $this->line('   • '.count($generatedMigrations).' consolidated files');

        if (! empty($this->guardedFiles)) {
            $this->line('   • '.count($this->guardedFiles).' migration(s) excluded (guarded)');
        }

        if ($this->option('dry-run')) {
            $this->info('✅ Dry run completed successfully. No changes made.');

            return Command::SUCCESS;
        }

        // Ask for confirmation BEFORE writing or moving anything.
        // Nothing on disk is touched until the user says yes.
        $archiver = new MigrationArchiver;
        $preview = $archiver->preview($migrationFiles);

        $this->newLine();
        $this->warn('The following migrations will be archived:');
        $this->line("   Archive location: {$preview['archivePath']}");
        $this->line("   Files to archive: {$preview['filesToArchive']}");
        $this->line('   Total size: '.round($preview['estimatedSize'] / 1024, 2).' KB');

        $confirm = $this->confirm('Continue?', true);

        if (! $confirm) {
            $this->info('Cancelled. No files were written or moved.');

            return Command::SUCCESS;
        }

        // Archive first (writes a manifest, then moves the originals out).
        // If the write step below fails, everything is still recoverable
        // from the archive folder named in the error message.
        $archiver->archive($migrationFiles);

        // Write generated migrations to the migration directory
        $migrationPath = database_path('migrations');

        try {
            foreach ($generatedMigrations as $migration) {
                $filePath = $migrationPath.'/'.$migration['filename'];

                if (file_put_contents($filePath, $migration['content']) === false) {
                    throw new \RuntimeException("Could not write {$filePath}");
                }
            }
        } catch (\Throwable $e) {
            $this->error('❌ Failed to write squashed migrations: '.$e->getMessage());
            $this->error('Your original migrations are safe in: '.$archiver->getArchivePath());
            $this->error('Move them back from there to restore the previous state.');

            return Command::FAILURE;
        }

        $this->info('✨ Migrations squashed and archived successfully!');
        $this->newLine();
        $this->line('Run `php artisan migrate:fresh` to verify the squashed migrations.');

        return Command::SUCCESS;
    }

    /**
     * Clean temporary directory.
     */
    protected function cleanupTempDir(string $dir): void
    {
        $files = glob($dir.'/*');

        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->cleanupTempDir($file);
            } else {
                unlink($file);
            }
        }

        rmdir($dir);
    }
}
