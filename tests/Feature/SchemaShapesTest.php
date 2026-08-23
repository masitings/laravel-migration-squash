<?php

/**
 * FR-6.7 — schema shapes that are easy to get wrong.
 *
 * Each case builds a real table in a sandbox, squashes it through the full
 * introspect → generate → verify pipeline, and asserts the schema round trips
 * exactly. toMatchSchema() gives a readable diff when one does not.
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
 * Build the schema with $build, then squash and replay it, returning the
 * before and after snapshots.
 *
 * @return array{before: array<string, mixed>, after: array<string, mixed>, files: array<int, string>}
 */
function roundTripSchema(Closure $build): array
{
    $source = SandboxConnectionFactory::create('sqlite');
    $replay = SandboxConnectionFactory::create('sqlite');
    $tempDir = sys_get_temp_dir().'/shape-'.uniqid();
    mkdir($tempDir, 0755, true);

    try {
        $build($source);

        $before = (new SchemaIntrospector($source))->getSnapshot();

        $generator = new SquashedMigrationGenerator;
        $tables = [];
        $contents = [];

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
            $contents[] = $generator->generate($table, "{$name}.php");
        }

        $fk = $generator->generateForeignKeyMigration($tables);

        if ($fk !== null) {
            $contents[] = $fk;
        }

        foreach ($contents as $i => $content) {
            file_put_contents(sprintf('%s/2024_01_01_%06d_squashed.php', $tempDir, $i), $content);
        }

        (new SandboxRunner($replay))->run(glob($tempDir.'/*.php'));

        $after = (new SchemaIntrospector($replay))->getSnapshot();

        return ['before' => $before, 'after' => $after, 'files' => $contents];
    } finally {
        array_map('unlink', glob($tempDir.'/*') ?: []);
        rmdir($tempDir);
        SandboxConnectionFactory::destroy($source);
        SandboxConnectionFactory::destroy($replay);
    }
}

test('a table with no id column round trips', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('settings', function ($table) {
            $table->string('key');
            $table->text('value');
        });
    });

    expect($result['after'])->toMatchSchema($result['before']);
});

test('a pivot table with a composite primary key round trips', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('role_user', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['role_id', 'user_id']);
        });
    });

    expect($result['after'])->toMatchSchema($result['before'])
        ->and($result['files'][0])->toContain("\$table->primary(['role_id', 'user_id']);");
});

test('a composite unique index round trips', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('bookings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->date('starts_on');
            $table->unique(['room_id', 'starts_on']);
        });
    });

    expect($result['after'])->toMatchSchema($result['before'])
        ->and($result['files'][0])->toContain("\$table->unique(['room_id', 'starts_on']);");
});

test('a self-referencing foreign key round trips', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('SET NULL');
        });
    });

    expect($result['after'])->toMatchSchema($result['before']);
});

test('columns with defaults round trip', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('articles', function ($table) {
            $table->id();
            $table->string('title')->default('Untitled');
            $table->integer('views')->default(0);
            $table->boolean('published')->default(false);
            $table->text('summary')->nullable();
        });
    });

    expect($result['after'])->toMatchSchema($result['before']);
});

test('a circular foreign key pair round trips via the deferred FK migration', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('users', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable();
        });

        Schema::connection($connection)->create('teams', function ($table) {
            $table->id();
            $table->unsignedBigInteger('owner_id')->nullable();
        });

        Schema::connection($connection)->table('users', function ($table) {
            $table->foreign('team_id')->references('id')->on('teams');
        });

        Schema::connection($connection)->table('teams', function ($table) {
            $table->foreign('owner_id')->references('id')->on('users');
        });
    });

    // Neither create statement carries a foreign key; they all live in the
    // separate file, which is what makes the cycle resolvable.
    expect($result['after'])->toMatchSchema($result['before'])
        ->and($result['files'][0])->not->toContain('->foreign(')
        ->and($result['files'][1])->not->toContain('->foreign(')
        ->and(end($result['files']))->toContain('->foreign(');
});

test('every generated file for these shapes is valid PHP', function () {
    $result = roundTripSchema(function (string $connection) {
        Schema::connection($connection)->create('settings', function ($table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    });

    foreach ($result['files'] as $content) {
        $file = sys_get_temp_dir().'/shape-lint-'.uniqid().'.php';
        file_put_contents($file, $content);
        exec("php -l {$file} 2>&1", $output, $exitCode);
        unlink($file);

        expect($exitCode)->toBe(0, implode("\n", $output));
    }
});
