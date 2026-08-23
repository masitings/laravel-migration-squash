<?php

/**
 * Coverage for the MySQL sandbox path.
 *
 * These tests need a reachable MySQL/MariaDB server. They skip themselves when
 * one is not available, so the suite stays green on a laptop with only SQLite.
 * CI provides a MySQL service, so there they actually run.
 *
 * Connection details come from the standard DB_* env vars.
 */

use Illuminate\Support\Facades\DB;
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

beforeEach(function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable; set DB_HOST/DB_USERNAME/DB_PASSWORD to run these.');
    }
});

test('MySQL sandbox creates a real database that can be queried', function () {
    $name = SandboxConnectionFactory::create('mysql');

    try {
        $database = config("database.connections.{$name}.database");

        expect($database)->toStartWith(SandboxConnectionFactory::SANDBOX_DB_PREFIX);

        // Would throw "Unknown database" if CREATE DATABASE had not run.
        $selected = DB::connection($name)->select('SELECT DATABASE() as db')[0]->db;

        expect($selected)->toBe($database);
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});

test('destroy drops the MySQL sandbox database', function () {
    $name = SandboxConnectionFactory::create('mysql');
    $database = config("database.connections.{$name}.database");

    SandboxConnectionFactory::destroy($name);

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306')),
        env('DB_USERNAME', 'root'),
        env('DB_PASSWORD', ''),
    );

    $rows = $pdo->query(
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($database)
    )->fetchAll();

    expect($rows)->toBeEmpty();
});

test('destroy refuses to drop a database without the sandbox prefix', function () {
    $name = SandboxConnectionFactory::create('mysql');
    $realDatabase = 'not_a_sandbox_'.uniqid();

    DB::connection($name)->statement("CREATE DATABASE `{$realDatabase}`");

    // Point the connection at a database this factory did not create.
    config()->set("database.connections.{$name}.database", $realDatabase);

    SandboxConnectionFactory::destroy($name);

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306')),
        env('DB_USERNAME', 'root'),
        env('DB_PASSWORD', ''),
    );

    $stillThere = $pdo->query(
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($realDatabase)
    )->fetchAll();

    $pdo->exec("DROP DATABASE IF EXISTS `{$realDatabase}`");

    expect($stillThere)->toHaveCount(1);
});

test('SandboxRunner guard still applies to MySQL connections', function () {
    $name = SandboxConnectionFactory::create('mysql');

    try {
        expect($name)->toStartWith('squash-sandbox-');
        expect(fn () => new SandboxRunner('mysql'))
            ->toThrow(RuntimeException::class, 'Sandbox safety check failed');
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});

test('MySQL introspection reads enum allowed values from the raw type', function () {
    $name = SandboxConnectionFactory::create('mysql');

    try {
        Schema::connection($name)->create('articles', function ($table) {
            $table->id();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
        });

        $snapshot = (new SchemaIntrospector($name))->getSnapshot();

        $status = collect($snapshot['tables']['articles']['columns'])
            ->firstWhere('name', 'status');

        expect($status['type'])->toBe('enum')
            ->and($status['allowed_values'])->toBe(['draft', 'published', 'archived'])
            ->and($status['length'])->toBeNull();
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});

test('MySQL end-to-end: introspect -> generate -> verify produces an empty diff', function () {
    $name = SandboxConnectionFactory::create('mysql');

    try {
        Schema::connection($name)->create('teams', function ($table) {
            $table->id();
            $table->string('name', 120);
            $table->timestamps();
        });

        Schema::connection($name)->create('members', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('email')->unique();
            $table->boolean('is_admin')->default(false);
            $table->enum('role', ['owner', 'editor', 'viewer']);
            $table->text('bio')->nullable();
            $table->timestamps();
            $table->index('team_id');
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('CASCADE');
        });

        $introspector = new SchemaIntrospector($name);
        $before = $introspector->getSnapshot();

        expect($before['tables'])->toHaveKeys(['teams', 'members']);

        // Rebuild Table objects and generate migrations, the same way the
        // command does.
        $generator = new SquashedMigrationGenerator;
        $tables = [];
        $files = [];

        foreach ($before['tables'] as $tableName => $data) {
            $table = new Table($tableName);

            foreach ($data['columns'] as $col) {
                $table->addColumn(new Column(
                    name: $col['name'],
                    type: $col['type'],
                    nullable: $col['nullable'],
                    default: $col['default'],
                    length: $col['length'],
                    unsigned: $col['unsigned'],
                    collation: $col['collation'],
                    autoIncrement: $col['auto_increment'],
                    allowedValues: $col['allowed_values'] ?? [],
                ));
            }

            foreach ($data['indexes'] as $idx) {
                $table->addIndex(new Index($idx['name'], $idx['type'], $idx['columns'], $idx['options']));
            }

            foreach ($data['foreign_keys'] as $fk) {
                $table->addForeignKey(new ForeignKey(
                    name: $fk['name'],
                    column: $fk['column'],
                    referencedTable: $fk['referenced_table'],
                    referencedColumn: $fk['referenced_column'],
                    onUpdate: $fk['on_update'],
                    onDelete: $fk['on_delete'],
                ));
            }

            $tables[$tableName] = $table;
            $files[] = $generator->generate($table, "0000_00_00_00000{$tableName}_table.php");
        }

        $fkMigration = $generator->generateForeignKeyMigration($tables);

        expect($fkMigration)->not->toBeNull();

        $files[] = $fkMigration;

        // Write them out and replay against a fresh MySQL sandbox.
        $tempDir = sys_get_temp_dir().'/squash-mysql-'.uniqid();
        mkdir($tempDir, 0755, true);

        foreach ($files as $i => $content) {
            file_put_contents(sprintf('%s/2024_01_01_%06d_squashed.php', $tempDir, $i), $content);

            $lintFile = $tempDir.'/lint.php';
            file_put_contents($lintFile, $content);
            exec("php -l {$lintFile} 2>&1", $output, $exitCode);
            expect($exitCode)->toBe(0, implode("\n", $output));
            unlink($lintFile);
        }

        $verifyName = SandboxConnectionFactory::create('mysql');

        try {
            (new SandboxRunner($verifyName))->run(glob($tempDir.'/*.php'));

            $after = (new SchemaIntrospector($verifyName))->getSnapshot();
            $diff = (new SchemaComparator)->compare($before, $after);

            expect($diff->isEmpty())->toBeTrue($diff->formatMessage());
        } finally {
            SandboxConnectionFactory::destroy($verifyName);
        }

        array_map('unlink', glob($tempDir.'/*'));
        rmdir($tempDir);
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});
