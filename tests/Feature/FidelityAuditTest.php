<?php

/**
 * Fidelity audit.
 *
 * Verification is only worth something if it actually notices a difference.
 * Each case here builds two schemas that differ in exactly one attribute and
 * asserts the comparator reports it. A case that fails is a silent hole: the
 * package would say "verification passed" on a schema that changed.
 *
 * The collation case is here because it used to fail. Both introspectors hard
 * coded `'collation' => null`, so every collation compared equal to every
 * other one.
 */

use Illuminate\Support\Facades\Schema;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;

/**
 * Build two sandboxes on the given driver and return their snapshots.
 */
function twoSchemas(string $driver, Closure $left, Closure $right): array
{
    $a = SandboxConnectionFactory::create($driver);
    $b = SandboxConnectionFactory::create($driver);

    try {
        $left($a);
        $right($b);

        return [
            (new SchemaIntrospector($a))->getSnapshot(),
            (new SchemaIntrospector($b))->getSnapshot(),
        ];
    } finally {
        SandboxConnectionFactory::destroy($a);
        SandboxConnectionFactory::destroy($b);
    }
}

test('a nullability difference is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name')),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name')->nullable()),
    );

    expect($b)->toDifferFromSchema($a, ['column_nullable_mismatch']);
});

test('a default difference is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->integer('n')->default(1)),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->integer('n')->default(2)),
    );

    expect($b)->toDifferFromSchema($a, ['column_default_mismatch']);
});

test('a length difference is caught on MySQL', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    [$a, $b] = twoSchemas('mysql',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 100)),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 200)),
    );

    expect($b)->toDifferFromSchema($a, ['column_length_mismatch']);
});

test('SQLite itself discards varchar length, so length is not verifiable there', function () {
    // Laravel's SQLite grammar emits `"name" varchar not null` with no length
    // at all, for any string size. Nothing downstream can recover it, so on a
    // SQLite application these two schemas genuinely are the same schema.
    //
    // This is recorded rather than fixed because it is a property of SQLite,
    // and because the sandbox now follows the application driver: a MySQL
    // application is introspected on MySQL, where length IS compared (see the
    // test above). Deliberately squashing a MySQL app through --driver=sqlite
    // is what would lose it, and the command warns about exactly that.
    [$a, $b] = twoSchemas('sqlite',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 100)),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 200)),
    );

    expect($b)->toMatchSchema($a);
});

test('a type difference is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('v')),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->text('v')),
    );

    expect($b)->toDifferFromSchema($a, ['column_type_mismatch']);
});

test('a missing column is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        function ($c) {
            Schema::connection($c)->create('t', function ($t) {
                $t->id();
                $t->string('email');
            });
        },
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->id()),
    );

    expect($b)->toDifferFromSchema($a, ['column_missing']);
});

test('a missing index is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        function ($c) {
            Schema::connection($c)->create('t', function ($t) {
                $t->id();
                $t->string('email')->unique();
            });
        },
        function ($c) {
            Schema::connection($c)->create('t', function ($t) {
                $t->id();
                $t->string('email');
            });
        },
    );

    expect($b)->toDifferFromSchema($a, ['index_missing']);
});

test('a unique index downgraded to a plain index is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        function ($c) {
            Schema::connection($c)->create('t', function ($t) {
                $t->id();
                $t->string('email')->unique();
            });
        },
        function ($c) {
            Schema::connection($c)->create('t', function ($t) {
                $t->id();
                $t->string('email')->index();
            });
        },
    );

    expect($b)->toDifferFromSchema($a, ['index_missing', 'extra_index']);
});

test('a missing foreign key is caught', function () {
    $build = function (bool $withFk) {
        return function ($c) use ($withFk) {
            Schema::connection($c)->create('parents', fn ($t) => $t->id());
            Schema::connection($c)->create('children', function ($t) use ($withFk) {
                $t->id();
                $t->unsignedBigInteger('parent_id');

                if ($withFk) {
                    $t->foreign('parent_id')->references('id')->on('parents');
                }
            });
        };
    };

    [$a, $b] = twoSchemas('sqlite', $build(true), $build(false));

    expect($b)->toDifferFromSchema($a, ['foreign_key_missing']);
});

test('a changed onDelete rule is caught', function () {
    $build = function (string $onDelete) {
        return function ($c) use ($onDelete) {
            Schema::connection($c)->create('parents', fn ($t) => $t->id());
            Schema::connection($c)->create('children', function ($t) use ($onDelete) {
                $t->id();
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->foreign('parent_id')->references('id')->on('parents')->onDelete($onDelete);
            });
        };
    };

    [$a, $b] = twoSchemas('sqlite', $build('CASCADE'), $build('SET NULL'));

    expect($b)->toDifferFromSchema($a, ['foreign_key_attribute_mismatch']);
});

test('a missing table is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        function ($c) {
            Schema::connection($c)->create('kept', fn ($t) => $t->id());
            Schema::connection($c)->create('dropped', fn ($t) => $t->id());
        },
        fn ($c) => Schema::connection($c)->create('kept', fn ($t) => $t->id()),
    );

    expect($b)->toDifferFromSchema($a, ['table_missing']);
});

test('an enum value difference is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->enum('s', ['a', 'b'])),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->enum('s', ['a', 'b', 'c'])),
    );

    expect($b)->toDifferFromSchema($a, ['column_enum_values_mismatch']);
});

test('a SQLite collation difference is caught', function () {
    [$a, $b] = twoSchemas('sqlite',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name')),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name')->collation('NOCASE')),
    );

    expect($b)->toDifferFromSchema($a, ['column_collation_mismatch']);
});

test('a MySQL collation difference is caught', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    [$a, $b] = twoSchemas('mysql',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 100)),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 100)->collation('utf8mb4_bin')),
    );

    expect($b)->toDifferFromSchema($a, ['column_collation_mismatch']);
});

test('a MySQL unsigned difference is caught', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    [$a, $b] = twoSchemas('mysql',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->bigInteger('n')),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->unsignedBigInteger('n')),
    );

    expect($b)->toDifferFromSchema($a, ['column_unsigned_mismatch']);
});

test('a MySQL column comment difference is caught', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    [$a, $b] = twoSchemas('mysql',
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 50)),
        fn ($c) => Schema::connection($c)->create('t', fn ($t) => $t->string('name', 50)->comment('the name')),
    );

    expect($b)->toDifferFromSchema($a, ['column_comment_mismatch']);
});

test('identical schemas produce no difference at all', function () {
    $build = function ($c) {
        Schema::connection($c)->create('t', function ($t) {
            $t->id();
            $t->string('email', 190)->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    };

    [$a, $b] = twoSchemas('sqlite', $build, $build);

    expect($b)->toMatchSchema($a);
});
