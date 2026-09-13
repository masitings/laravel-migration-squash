<?php

/**
 * The generated migrations have to roll back, not just migrate.
 *
 * No test ever ran `down()`, and that is exactly where a bug was hiding: the
 * foreign key migration emitted one `dropForeign()` call holding every column
 * at once. Laravel builds a single index name out of an array of columns, so
 * `dropForeign(['parent_id', 'project_id', 'user_id'])` looks for a constraint
 * called `comments_parent_id_project_id_user_id_foreign`, which never existed.
 * Every rollback would have failed.
 */

use Illuminate\Support\Facades\Schema;
use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;

/**
 * Rebuild Table objects from a snapshot, the way the command does.
 *
 * @return array<string, Table>
 */
function tablesFromSnapshot(array $snapshot): array
{
    $tables = [];

    foreach ($snapshot['tables'] as $name => $data) {
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
                comment: $col['comment'] ?? null,
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

        $tables[$name] = $table;
    }

    return $tables;
}

test('the foreign key migration drops each key separately', function () {
    $table = new Table('comments');
    $table->addForeignKey(new ForeignKey('a', 'parent_id', 'comments', 'id', null, 'CASCADE'));
    $table->addForeignKey(new ForeignKey('b', 'project_id', 'projects', 'id', null, 'CASCADE'));
    $table->addForeignKey(new ForeignKey('c', 'user_id', 'users', 'id', null, null));

    $code = (new SquashedMigrationGenerator)->generateForeignKeyMigration(['comments' => $table]);

    expect($code)->toContain("\$table->dropForeign(['parent_id']);")
        ->and($code)->toContain("\$table->dropForeign(['project_id']);")
        ->and($code)->toContain("\$table->dropForeign(['user_id']);")
        // The shape that made every rollback fail.
        ->and($code)->not->toContain("dropForeign(['parent_id', 'project_id', 'user_id'])");
});

test('generated migrations roll back cleanly on MySQL', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    $source = SandboxConnectionFactory::create('mysql');
    $replay = SandboxConnectionFactory::create('mysql');
    $dir = sys_get_temp_dir().'/rollback-'.uniqid();
    mkdir($dir, 0755, true);

    try {
        Schema::connection($source)->create('teams', fn ($t) => $t->id());

        Schema::connection($source)->create('users', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->foreign('team_id')->references('id')->on('teams');
        });

        Schema::connection($source)->create('comments', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
            $t->foreign('parent_id')->references('id')->on('comments')->onDelete('CASCADE');
        });

        $snapshot = (new SchemaIntrospector($source))->getSnapshot();
        $tables = tablesFromSnapshot($snapshot);

        $generator = new SquashedMigrationGenerator;
        $i = 0;

        foreach ($tables as $name => $table) {
            file_put_contents(
                sprintf('%s/2024_01_01_%06d_create_%s.php', $dir, $i++, $name),
                $generator->generate($table, "{$name}.php"),
            );
        }

        file_put_contents(
            sprintf('%s/2024_01_01_%06d_add_foreign_keys.php', $dir, $i),
            $generator->generateForeignKeyMigration($tables),
        );

        $runner = new SandboxRunner($replay);
        $runner->run(glob($dir.'/*.php'));

        // Roll the whole thing back. Before the fix this threw:
        //   Can't DROP FOREIGN KEY `comments_parent_id_user_id_foreign`
        $runner->rollback(glob($dir.'/*.php'));

        $remaining = (new SchemaIntrospector($replay))->getSnapshot();

        expect($remaining['tables'])->toBeEmpty();
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
        SandboxConnectionFactory::destroy($source);
        SandboxConnectionFactory::destroy($replay);
    }
});
