<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MigrationSquash\Introspection\SchemaIntrospector;

it('introspects postgres columns correctly', function () {
    Config::set('database.connections.test_pgsql', [
        'driver' => 'pgsql',
    ]);

    $connectionMock = Mockery::mock(Connection::class);

    // Default collation query
    $connectionMock->shouldReceive('selectOne')
        ->with('SELECT datcollate FROM pg_database WHERE datname = current_database()')
        ->andReturn((object) ['datcollate' => 'en_US.UTF-8']);

    // Enum query for user-defined type
    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT e\.enumlabel/'), ['user_status'])
        ->andReturn([
            (object) ['enumlabel' => 'active'],
            (object) ['enumlabel' => 'inactive'],
        ]);

    // Columns query
    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT.*c\.column_name.*FROM information_schema\.columns/s'), ['users'])
        ->andReturn([
            (object) [
                'column_name' => 'id',
                'data_type' => 'bigint',
                'udt_name' => 'int8',
                'is_nullable' => 'NO',
                'column_default' => "nextval('users_id_seq'::regclass)",
                'character_maximum_length' => null,
                'numeric_precision' => 64,
                'collation_name' => null,
                'is_identity' => 'NO',
                'column_comment' => 'User ID',
            ],
            (object) [
                'column_name' => 'name',
                'data_type' => 'character varying',
                'udt_name' => 'varchar',
                'is_nullable' => 'NO',
                'column_default' => null,
                'character_maximum_length' => 255,
                'numeric_precision' => null,
                'collation_name' => 'en_US.UTF-8',
                'is_identity' => 'NO',
                'column_comment' => null,
            ],
            (object) [
                'column_name' => 'status',
                'data_type' => 'USER-DEFINED',
                'udt_name' => 'user_status',
                'is_nullable' => 'NO',
                'column_default' => "'active'::user_status",
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'collation_name' => null,
                'is_identity' => 'NO',
                'column_comment' => null,
            ],
            (object) [
                'column_name' => 'is_admin',
                'data_type' => 'boolean',
                'udt_name' => 'bool',
                'is_nullable' => 'NO',
                'column_default' => 'false',
                'character_maximum_length' => null,
                'numeric_precision' => null,
                'collation_name' => null,
                'is_identity' => 'NO',
                'column_comment' => null,
            ],
        ]);

    DB::shouldReceive('connection')
        ->with('test_pgsql')
        ->andReturn($connectionMock);

    $introspector = new SchemaIntrospector('test_pgsql');

    $ref = new ReflectionMethod(SchemaIntrospector::class, 'getPostgresColumns');
    $ref->setAccessible(true);
    $columns = $ref->invoke($introspector, 'users');

    expect($columns)->toHaveCount(4)
        ->and($columns[0]['name'])->toBe('id')
        ->and($columns[0]['type'])->toBe('bigint')
        ->and($columns[0]['auto_increment'])->toBeTrue()
        ->and($columns[0]['default'])->toBeNull()
        ->and($columns[0]['comment'])->toBe('User ID')
        ->and($columns[1]['name'])->toBe('name')
        ->and($columns[1]['type'])->toBe('varchar')
        ->and($columns[1]['length'])->toBe(255)
        ->and($columns[1]['collation'])->toBeNull()
        ->and($columns[2]['name'])->toBe('status')
        ->and($columns[2]['type'])->toBe('enum')
        ->and($columns[2]['allowed_values'])->toBe(['active', 'inactive'])
        ->and($columns[2]['default'])->toBe('active')
        ->and($columns[3]['name'])->toBe('is_admin')
        ->and($columns[3]['type'])->toBe('boolean')
        ->and($columns[3]['default'])->toBeFalse();
});

it('introspects postgres indexes correctly', function () {
    Config::set('database.connections.test_pgsql', [
        'driver' => 'pgsql',
    ]);

    $connectionMock = Mockery::mock(Connection::class);

    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT.*c2\.relname AS index_name/s'), ['users'])
        ->andReturn([
            (object) [
                'index_name' => 'users_pkey',
                'is_unique' => true,
                'is_primary' => true,
                'am_name' => 'btree',
                'column_name' => 'id',
            ],
            (object) [
                'index_name' => 'users_email_unique',
                'is_unique' => true,
                'is_primary' => false,
                'am_name' => 'btree',
                'column_name' => 'email',
            ],
        ]);

    DB::shouldReceive('connection')
        ->with('test_pgsql')
        ->andReturn($connectionMock);

    $introspector = new SchemaIntrospector('test_pgsql');

    $ref = new ReflectionMethod(SchemaIntrospector::class, 'getPostgresIndexes');
    $ref->setAccessible(true);
    $indexes = $ref->invoke($introspector, 'users');

    expect($indexes)->toHaveCount(2)
        ->and($indexes[0]['key_name'])->toBe('users_pkey')
        ->and($indexes[0]['index_type'])->toBe('primary')
        ->and($indexes[0]['columns'])->toBe(['id'])
        ->and($indexes[1]['key_name'])->toBe('users_email_unique')
        ->and($indexes[1]['index_type'])->toBe('unique')
        ->and($indexes[1]['columns'])->toBe(['email']);
});

it('introspects postgres foreign keys correctly', function () {
    Config::set('database.connections.test_pgsql', [
        'driver' => 'pgsql',
    ]);

    $connectionMock = Mockery::mock(Connection::class);

    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT.*tc\.constraint_name/s'), ['posts'])
        ->andReturn([
            (object) [
                'constraint_name' => 'posts_user_id_foreign',
                'column_name' => 'user_id',
                'referenced_table' => 'users',
                'referenced_column' => 'id',
                'on_update' => 'NO ACTION',
                'on_delete' => 'CASCADE',
            ],
        ]);

    DB::shouldReceive('connection')
        ->with('test_pgsql')
        ->andReturn($connectionMock);

    $introspector = new SchemaIntrospector('test_pgsql');

    $ref = new ReflectionMethod(SchemaIntrospector::class, 'getPostgresForeignKeys');
    $ref->setAccessible(true);
    $fks = $ref->invoke($introspector, 'posts');

    expect($fks)->toHaveCount(1)
        ->and($fks[0]['name'])->toBe('posts_user_id_foreign')
        ->and($fks[0]['column'])->toBe('user_id')
        ->and($fks[0]['referenced_table'])->toBe('users')
        ->and($fks[0]['referenced_column'])->toBe('id')
        ->and($fks[0]['on_update'])->toBeNull()
        ->and($fks[0]['on_delete'])->toBe('CASCADE');
});
