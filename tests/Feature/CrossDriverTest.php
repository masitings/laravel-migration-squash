<?php

/**
 * The sandbox engine must match the engine the application runs on.
 *
 * Squashing a MySQL app through a SQLite sandbox used to "pass" verification
 * and produce migrations that cannot run on MySQL at all. SQLite has no
 * unsigned bigint, so introspection reports plain `integer`, the generator
 * emits `increments()` instead of `id()`, and every foreign key onto that
 * primary key fails with errno 150. Verification reported success because it
 * compared SQLite against SQLite.
 *
 * These tests pin the driver-selection rule that prevents it, and keep a
 * demonstration of the underlying mismatch so nobody re-introduces the
 * "SQLite is always fine, it's faster" shortcut.
 */

use Illuminate\Support\Facades\Schema;
use MigrationSquash\Console\Commands\MigrateSquashCommand;
use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;

test('the sandbox driver follows the application driver', function (string $appDriver, string $expected) {
    config()->set('database.default', 'app_under_test');
    config()->set('database.connections.app_under_test', ['driver' => $appDriver, 'database' => 'placeholder']);
    config()->set('migrationsquash.sandbox.driver', null);

    $command = new class extends MigrateSquashCommand
    {
        public function driverForTest(): ?string
        {
            return $this->sandboxDriverFor($this->applicationDriver());
        }
    };

    expect($command->driverForTest())->toBe($expected);
})->with([
    ['mysql', 'mysql'],
    ['mariadb', 'mysql'],
    ['sqlite', 'sqlite'],
]);

test('an unsupported application driver has no sandbox, so the squash is refused', function () {
    config()->set('database.default', 'app_under_test');
    config()->set('database.connections.app_under_test', ['driver' => 'pgsql', 'database' => 'placeholder']);

    $command = new class extends MigrateSquashCommand
    {
        public function driverForTest(): ?string
        {
            return $this->sandboxDriverFor($this->applicationDriver());
        }
    };

    // null means "refuse", not "fall back to SQLite".
    expect($command->driverForTest())->toBeNull();
});

test('SQLite cannot represent unsigned bigint, which is why the driver has to match', function () {
    $connection = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($connection)->create('members', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
        });

        $snapshot = (new SchemaIntrospector($connection))->getSnapshot();
        $columns = collect($snapshot['tables']['members']['columns'])->keyBy('name');

        // This is the loss of information, recorded so the reason stays visible.
        expect($columns['id']['type'])->toBe('integer')
            ->and($columns['team_id']['type'])->toBe('integer')
            ->and($columns['team_id']['unsigned'])->toBeFalse();

        $table = new Table('members');

        foreach ($snapshot['tables']['members']['columns'] as $col) {
            $table->addColumn(new Column(
                name: $col['name'],
                type: $col['type'],
                nullable: $col['nullable'],
                default: $col['default'],
                length: $col['length'],
                unsigned: $col['unsigned'],
                collation: $col['collation'],
                autoIncrement: $col['auto_increment'],
            ));
        }

        $code = (new SquashedMigrationGenerator)->generate($table, 'x.php');

        // On MySQL increments() is int, not bigint, so a foreign key from a
        // bigint column onto it fails with errno 150.
        expect($code)->toContain("\$table->increments('id')")
            ->and($code)->not->toContain('$table->id()');
    } finally {
        SandboxConnectionFactory::destroy($connection);
    }
});

test('a MySQL app squashed through a MySQL sandbox round trips exactly', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    $result = null;
    $truth = SandboxConnectionFactory::create('mysql');
    $replay = SandboxConnectionFactory::create('mysql');
    $dir = sys_get_temp_dir().'/xd-'.uniqid();
    mkdir($dir, 0755, true);

    try {
        $build = function (string $connection) {
            Schema::connection($connection)->create('teams', function ($table) {
                $table->id();
                $table->string('name', 120);
            });

            Schema::connection($connection)->create('members', function ($table) {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->unsignedInteger('visits')->default(0);
                $table->string('email', 190)->unique();
                $table->foreign('team_id')->references('id')->on('teams');
            });
        };

        $build($truth);

        $before = (new SchemaIntrospector($truth))->getSnapshot();

        $generator = new SquashedMigrationGenerator;
        $tables = [];
        $files = [];

        foreach ($before['tables'] as $name => $data) {
            $table = new Table($name);

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
                $table->addIndex(new Index(
                    $idx['name'], $idx['type'], $idx['columns'], $idx['options']
                ));
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

            $tables[$name] = $table;
            $files[] = $generator->generate($table, "{$name}.php");
        }

        $fkMigration = $generator->generateForeignKeyMigration($tables);

        if ($fkMigration !== null) {
            $files[] = $fkMigration;
        }

        foreach ($files as $i => $content) {
            file_put_contents(sprintf('%s/2024_01_01_%06d_s.php', $dir, $i), $content);
        }

        (new SandboxRunner($replay))->run(glob($dir.'/*.php'));

        $result = (new SchemaIntrospector($replay))->getSnapshot();
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
        SandboxConnectionFactory::destroy($truth);
        SandboxConnectionFactory::destroy($replay);
    }

    expect($result)->toMatchSchema($before);
});
