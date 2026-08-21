<?php

namespace MigrationSquash\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MigrationSquash\Discovery\MigrationScanner;
use MigrationSquash\Discovery\TableGrouping;
use MigrationSquash\Guards\RawSqlDetector;
use MigrationSquash\Guards\DataSeedDetector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Verification\SchemaComparator;
use MigrationSquash\Archiving\MigrationArchiver;
use MigrationSquash\Schema\Table;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\ForeignKey;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:squash')]
class MigrateSquashCommand extends Command
{
    protected $signature = '
        migrate:squash 
                    {--dry-run : Generate and verify without archiving old migrations}
                    {--check : Only check for guarded migrations, don\'t run squash}
                    {--driver=mysql|sqlite : Force sandbox database driver}
                    {--table= : Squash only specific tables (can be repeated)}
    ';

    protected $description = 'Combine multiple migration files into a single consolidated migration per table';

    /**
     * Execute the console command
     */
    public function handle(): int
    {
        $this->info('🔍 Laravel Migration Squasher');
        $this->newLine();

        try {
            // Step 1: Scan migrations
            $this->step1_DetectTable();

            // Step 2: Check for guarded migrations
            if ($this->option('check')) {
                return $this->handleCheckMode();
            }

            // Step 3: Setup sandbox connection
            $sandboxConfig = $this->setupSandboxConnection();
            
            // Step 4: Run original migrations in sandbox
            $this->step4_RunOriginalInSandbox($migrationFiles);
            
            // Step 5: Introspect schema
            $snapshotBefore = $this->step5_IntrospectSchema($connectionName);
            
            // Step 6: Generate squashed migrations
            $generatedMigrations = $this->step6_GenerateSquashedMigrations($snapshotBefore);
            
            // Step 7: Verify generated schema
            $verificationResult = $this->step7_VerifyGeneratedSchema(
                $snapshotBefore,
                $generatedMigrations,
                $sandboxConfig
            );
            
            // Step 8: Report and optionally archive
            return $this->step8_ReportAndArchive(
                $verificationResult,
                $migrationFiles,
                $generatedMigrations
            );

        } catch (\Throwable $e) {
            $this->error("❌ {$e->getMessage()}");
            $this->error("Stack trace:\n{$e->getTraceAsString()}");
            return Command::FAILURE;
        }
    }

    /**
     * Step 1: Discover and group migrations by table
     */
    protected function step1_DetectTable()
    {
        $this->info('📂 Step 1: Scanning migrations...');
        
        $scanner = new MigrationScanner();
        $migrations = $scanner->scan();
        
        if (empty($migrations)) {
            $this->warn('⚠️ No migrations found in database/migrations/');
            return Command::FAILURE;
        }
        
        $this->line("   Found " . count($migrations) . " migration files");
        
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
            $migrations = $filtered;
            $this->line("   Filtered to " . count($migrations) . " migrations for specified tables");
        }
        
        return $migrations;
    }

    /**
     * Handle check mode - just detect problematic migrations
     */
    protected function handleCheckMode(): int
    {
        $this->info('🔒 Running in check mode...');
        
        $rawSqlDetector = new RawSqlDetector();
        $dataSeedDetector = new DataSeedDetector();
        
        $rawSqlIssues = $rawSqlDetector->detect($this->allMigrationFiles());
        $dataSeedIssues = $dataSeedDetector->detect($this->allMigrationFiles());
        
        $allIssues = array_merge($rawSqlIssues, $dataSeedIssues);
        
        if (empty($allIssues)) {
            $this->info('✅ All migrations are safe to squash');
            return Command::SUCCESS;
        }
        
        $this->error("❌ Found " . count($allIssues) . " migration(s) that cannot be squashed:");
        
        foreach ($allIssues as $file => $reason) {
            $relativePath = str_replace(database_path('migrations/') . '/', '', $file);
            $this->line("\n  • {$relativePath}");
            $this->line("    Reason: {$reason}", 'yellow');
        }
        
        $this->newLine();
        $this->warn('These migrations will be excluded from squash.');
        
        return Command::FAILURE;
    }

    /**
     * Set up sandbox connection
     */
    protected function setupSandboxConnection(): array
    {
        $this->info('🧪 Step 2: Setting up sandbox connection...');
        
        $forceDriver = $this->option('driver');
        
        if ($forceDriver) {
            $config = SandboxConnectionFactory::create($forceDriver);
        } else {
            // Auto-detect if MySQL is needed
            $migrationFiles = $this->allMigrationFiles();
            $needsMySQL = SandboxConnectionFactory::needsMySQL($migrationFiles);
            
            $configType = $needsMySQL ? 'mysql' : 'sqlite';
            $config = SandboxConnectionFactory::create($configType);
            
            if ($needsMySQL) {
                $this->info('ℹ️ Using MySQL sandbox due to MySQL-specific features');
            } else {
                $this->info('ℹ️ Using SQLite in-memory sandbox for speed');
            }
        }
        
        $this->line("   Connection name: {$config['name']}");
        
        return $config;
    }

    /**
     * Get list of all migration files
     */
    protected function allMigrationFiles(): array
    {
        $scanner = new MigrationScanner();
        return array_column($scanner->scan(), 'file');
    }

    /**
     * Step 4: Run original migrations in sandbox
     */
    protected function step4_RunOriginalInSandbox(array $migrationFiles): void
    {
        $this->info('⚡ Step 3: Running original migrations in sandbox...');
        
        $runner = new SandboxRunner($this->getConnectionFromConfig());
        $runner->fresh();
        
        $success = $runner->run($migrationFiles);
        
        if ($success) {
            $this->info('✅ Original migrations executed successfully');
        } else {
            throw new \RuntimeException('Failed to execute original migrations in sandbox');
        }
    }

    /**
     * Step 5: Introspect schema after running migrations
     */
    protected function step5_IntrospectSchema(string $connectionName): array
    {
        $this->info('📋 Step 4: Introspecting schema...');
        
        $introspector = new SchemaIntrospector($connectionName);
        $snapshot = $introspector->getSnapshot();
        
        $this->line("   Discovered " . count($snapshot['tables']) . " tables");
        
        return $snapshot;
    }

    /**
     * Step 6: Generate squashed migrations
     */
    protected function step6_GenerateSquashedMigrations(array $snapshot): array
    {
        $this->info('🏗️ Step 5: Generating squashed migrations...');
        
        $generator = new SquashedMigrationGenerator();
        $generatedMigrations = [];
        
        foreach ($snapshot['tables'] as $tableName => $tableData) {
            $timestamp = date('Y_m_d_His');
            $filename = "{$timestamp}_create_{$tableName}_table.php";
            
            // Reconstruct Table object from snapshot data for generation
            $columns = array_map(function($col) {
                return new Column(
                    name: $col['name'],
                    type: $col['type'],
                    nullable: $col['nullable'],
                    default: $col['default'],
                    length: $col['length'],
                    unsigned: $col['unsigned'],
                    collation: $col['collation'],
                    autoIncrement: $col['auto_increment'],
                );
            }, $tableData['columns']);
            
            $indexes = array_map(function($idx) {
                return new Index(
                    name: $idx['name'],
                    type: $idx['type'],
                    columns: $idx['columns'],
                    options: $idx['options'],
                );
            }, $tableData['indexes']);
            
            $foreignKeys = array_map(function($fk) {
                return new ForeignKey(
                    name: $fk['name'],
                    column: $fk['column'],
                    referencedTable: $fk['referenced_table'],
                    referencedColumn: $fk['referenced_column'],
                    onUpdate: $fk['on_update'],
                    onDelete: $fk['onDelete'],
                );
            }, $tableData['foreign_keys']);
            
            $table = (new Table(name: $tableName))
                ->addColumns($columns);
            
            foreach ($indexes as $index) {
                $table->indexes[] = $index;
            }
            
            foreach ($foreignKeys as $fk) {
                $table->foreignKeys[] = $fk;
            }
            
            $migrationContent = $generator->generate($table, $filename);
            
            $generatedMigrations[$tableName] = [
                'filename' => $filename,
                'content' => $migrationContent,
                'table' => $tableName,
            ];
            
            $this->line("   Generated: {$filename}");
        }
        
        return $generatedMigrations;
    }

    /**
     * Step 7: Verify generated schema matches original
     */
    protected function step7_VerifyGeneratedSchema(
        array $originalSnapshot,
        array $generatedMigrations,
        array $sandboxConfig
    ): \MigrationSquash\Verification\SchemaDiff {
        $this->info('✓ Step 6: Verifying generated schema...');
        
        // Fresh sandbox
        $runner = new SandboxRunner($this->getConnectionFromConfig());
        $runner->fresh();
        
        // Write generated migrations to temp dir and run them
        $tempDir = sys_get_temp_dir() . '/squash-' . uniqid();
        mkdir($tempDir);
        
        foreach ($generatedMigrations as $tableName => $migration) {
            $filePath = $tempDir . '/' . $migration['filename'];
            file_put_contents($filePath, $migration['content']);
        }
        
        $runner->run(glob($tempDir . '/*.php'));
        
        // Introspect again
        $introspector = new SchemaIntrospector($this->getConnectionFromConfig());
        $snapshotAfter = $introspector->getSnapshot();
        
        // Compare
        $comparator = new SchemaComparator();
        $diff = $comparator->compare($originalSnapshot, $snapshotAfter);
        
        // Cleanup
        $this->cleanupTempDir($tempDir);
        
        if ($diff->isEmpty()) {
            $this->info('✅ Schema verification passed!');
        } else {
            $this->error('❌ Schema verification failed!');
            $this->line($diff->formatMessage());
        }
        
        return $diff;
    }

    /**
     * Step 8: Report results and optionally archive
     */
    protected function step8_ReportAndArchive(
        \MigrationSquash\Verification\SchemaDiff $diff,
        array $migrationFiles,
        array $generatedMigrations
    ): int {
        if (! $diff->isEmpty()) {
            $this->newLine();
            $this->warn('Squashing ABORTED to prevent schema mismatch.');
            return Command::FAILURE;
        }
        
        $this->newLine();
        $this->info('📊 Summary:');
        $this->line("   • " . count($migrationFiles) . " original migration files");
        $this->line("   • " . count($generatedMigrations) . " consolidated files");
        
        if ($this->option('dry-run')) {
            $this->info('✅ Dry run completed successfully. No changes made.');
            return Command::SUCCESS;
        }
        
        // Ask for confirmation before archiving
        $archiver = new MigrationArchiver();
        $preview = $archiver->preview($migrationFiles);
        
        $this->newLine();
        $this->warn('The following migrations will be archived:');
        $this->line("   Archive location: {$preview['archivePath']}");
        $this->line("   Files to archive: {$preview['filesToArchive']}");
        $this->line("   Total size: " . round($preview['estimatedSize'] / 1024, 2) . " KB");
        
        $confirm = $this->confirm('Continue?', true);
        
        if (! $confirm) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }
        
        // Archive
        $archiver->archive($migrationFiles);
        
        $this->success('✨ Migrations archived successfully!');
        $this->newLine();
        $this->line('You can now clean your migration history using:');
        $this->line('  php artisan migrate:fresh');
        
        return Command::SUCCESS;
    }

    /**
     * Clean temporary directory
     */
    protected function cleanupTempDir(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->cleanupTempDir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }

    /**
     * Get connection name from current config
     */
    protected function getConnectionFromConfig(): string
    {
        // This should use the sandbox connection name
        return 'sqlite';
    }
}
