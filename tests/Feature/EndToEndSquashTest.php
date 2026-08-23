<?php

use Illuminate\Support\Facades\Schema;
use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;
use MigrationSquash\Verification\SchemaComparator;

test('end-to-end: introspect -> generate -> verify produces empty diff', function () {
    $sandboxName = SandboxConnectionFactory::create('sqlite');

    try {
        // Create tables directly in sandbox
        Schema::connection($sandboxName)->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamps();
        });

        Schema::connection($sandboxName)->create('posts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Step 1: Introspect original schema
        $introspector = new SchemaIntrospector($sandboxName);
        $originalSnapshot = $introspector->getSnapshot();

        expect($originalSnapshot['tables'])->toHaveKeys(['users', 'posts']);

        // Step 2: Generate squashed migrations
        $generator = new SquashedMigrationGenerator;
        $generatedMigrations = [];
        $allTables = [];

        $baseTimestamp = time();
        $i = 0;

        foreach ($originalSnapshot['tables'] as $tableName => $tableData) {
            $table = rebuildTable($tableName, $tableData);
            $allTables[$tableName] = $table;

            $filename = date('Y_m_d_His', $baseTimestamp + $i)."_create_{$tableName}_table.php";
            $code = $generator->generate($table, $filename);

            $generatedMigrations[] = [
                'filename' => $filename,
                'content' => $code,
            ];

            $i++;
        }

        // Generate FK file
        $fkCode = $generator->generateForeignKeyMigration($allTables);

        if ($fkCode !== null) {
            $generatedMigrations[] = [
                'filename' => date('Y_m_d_His', $baseTimestamp + $i + 1).'_add_foreign_keys.php',
                'content' => $fkCode,
            ];
        }

        // Step 3: All generated files must be syntactically valid
        foreach ($generatedMigrations as $migration) {
            $tempFile = sys_get_temp_dir().'/e2e_'.uniqid().'.php';
            file_put_contents($tempFile, $migration['content']);

            exec("php -l {$tempFile} 2>&1", $output, $exitCode);
            @unlink($tempFile);

            expect($exitCode)->toBe(0, "File {$migration['filename']} has syntax errors: ".implode("\n", $output));
            $output = [];
        }

        // Step 4: Run generated migrations in a fresh sandbox
        $runner = new SandboxRunner($sandboxName);
        $runner->fresh();

        $tempDir = sys_get_temp_dir().'/e2e-verify-'.uniqid();
        mkdir($tempDir, 0755, true);

        foreach ($generatedMigrations as $migration) {
            file_put_contents($tempDir.'/'.$migration['filename'], $migration['content']);
        }

        $runner->run(glob($tempDir.'/*.php'));

        // Step 5: Introspect again and compare
        $newSnapshot = $introspector->getSnapshot();

        $comparator = new SchemaComparator;
        $diff = $comparator->compare($originalSnapshot, $newSnapshot);

        if (! $diff->isEmpty()) {
            // Provide detailed output for debugging
            dump($diff->formatMessage());
        }

        expect($diff->isEmpty())->toBeTrue('Schema diff should be empty after round-trip');

        // Cleanup
        array_map('unlink', glob($tempDir.'/*'));
        rmdir($tempDir);
    } finally {
        SandboxConnectionFactory::destroy($sandboxName);
    }
});

test('SchemaIntrospector reads SQLite foreign keys via PRAGMA', function () {
    $sandboxName = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($sandboxName)->create('categories', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::connection($sandboxName)->create('products', function ($table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name');
            $table->foreign('category_id')->references('id')->on('categories');
        });

        $introspector = new SchemaIntrospector($sandboxName);
        $snapshot = $introspector->getSnapshot();

        // H-5: SQLite FK introspection must NOT return empty
        $productFks = $snapshot['tables']['products']['foreign_keys'];

        expect($productFks)->not->toBeEmpty()
            ->and($productFks[0]['column'])->toBe('category_id')
            ->and($productFks[0]['referenced_table'])->toBe('categories')
            ->and($productFks[0]['referenced_column'])->toBe('id');
    } finally {
        SandboxConnectionFactory::destroy($sandboxName);
    }
});

test('SchemaIntrospector normalizes SQLite column types', function () {
    $sandboxName = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($sandboxName)->create('test_types', function ($table) {
            $table->bigInteger('big_col');
            $table->integer('int_col');
            $table->string('str_col', 100);
            $table->text('text_col');
            $table->boolean('bool_col');
        });

        $introspector = new SchemaIntrospector($sandboxName);
        $snapshot = $introspector->getSnapshot();

        $columns = collect($snapshot['tables']['test_types']['columns'])->keyBy('name');

        // H-6: Types should be normalized, not raw PRAGMA output
        expect($columns['big_col']['type'])->toBe('integer')
            ->and($columns['int_col']['type'])->toBe('integer')
            ->and($columns['text_col']['type'])->toBe('text')
            ->and($columns['bool_col']['type'])->toBe('boolean');
    } finally {
        SandboxConnectionFactory::destroy($sandboxName);
    }
});

test('SchemaIntrospector reads SQLite index columns', function () {
    $sandboxName = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($sandboxName)->create('events', function ($table) {
            $table->id();
            $table->string('name');
            $table->date('event_date');
            $table->index(['name', 'event_date']);
        });

        $introspector = new SchemaIntrospector($sandboxName);
        $snapshot = $introspector->getSnapshot();

        $indexes = $snapshot['tables']['events']['indexes'];

        // H-7: Index columns must be populated, not empty
        $compositeIndex = collect($indexes)->first(fn ($idx) => count($idx['columns']) > 1);

        expect($compositeIndex)->not->toBeNull()
            ->and($compositeIndex['columns'])->toContain('name')
            ->and($compositeIndex['columns'])->toContain('event_date');
    } finally {
        SandboxConnectionFactory::destroy($sandboxName);
    }
});

/**
 * Helper to rebuild a Table object from snapshot data.
 */
function rebuildTable(string $name, array $data): Table
{
    $table = new Table(name: $name);

    foreach ($data['columns'] ?? [] as $col) {
        $table->addColumn(new Column(
            name: $col['name'],
            type: $col['type'],
            nullable: $col['nullable'],
            default: $col['default'],
            length: $col['length'] ?? null,
            unsigned: $col['unsigned'] ?? false,
            collation: $col['collation'] ?? null,
            autoIncrement: (bool) ($col['auto_increment'] ?? false),
        ));
    }

    foreach ($data['indexes'] ?? [] as $idx) {
        $table->addIndex(new Index(
            name: $idx['name'],
            type: $idx['type'],
            columns: $idx['columns'],
            options: $idx['options'] ?? null,
        ));
    }

    foreach ($data['foreign_keys'] ?? [] as $fk) {
        $table->addForeignKey(new ForeignKey(
            name: $fk['name'],
            column: $fk['column'],
            referencedTable: $fk['referenced_table'],
            referencedColumn: $fk['referenced_column'],
            onUpdate: $fk['on_update'] ?? null,
            onDelete: $fk['on_delete'] ?? null,
        ));
    }

    return $table;
}
