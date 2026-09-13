<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MigrationSquash\Introspection\SchemaIntrospector;

it('introspects sql server columns correctly', function () {
    Config::set('database.connections.test_sqlsrv', [
        'driver' => 'sqlsrv',
    ]);

    $connectionMock = Mockery::mock(Connection::class);

    // Default collation query
    $connectionMock->shouldReceive('selectOne')
        ->with("SELECT DATABASEPROPERTYEX(DB_NAME(), 'Collation') AS collation")
        ->andReturn((object) ['collation' => 'SQL_Latin1_General_CP1_CI_AS']);

    // Check constraint query for enum detection
    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT cc\.definition/'), ['users', 'status'])
        ->andReturn([
            (object) ['definition' => "([status] IN ('active', 'inactive'))"],
        ]);

    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT cc\.definition/'), Mockery::any())
        ->andReturn([]);

    // Columns query
    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT.*c\.COLUMN_NAME AS name.*FROM INFORMATION_SCHEMA\.COLUMNS/s'), ['users'])
        ->andReturn([
            (object) [
                'name' => 'id',
                'data_type' => 'bigint',
                'is_nullable' => 'NO',
                'column_default' => null,
                'character_maximum_length' => null,
                'collation_name' => null,
                'is_identity' => true,
                'comment' => 'User ID',
            ],
            (object) [
                'name' => 'name',
                'data_type' => 'nvarchar',
                'is_nullable' => 'NO',
                'column_default' => null,
                'character_maximum_length' => 255,
                'collation_name' => 'SQL_Latin1_General_CP1_CI_AS',
                'is_identity' => false,
                'comment' => null,
            ],
            (object) [
                'name' => 'status',
                'data_type' => 'varchar',
                'is_nullable' => 'NO',
                'column_default' => "('active')",
                'character_maximum_length' => 50,
                'collation_name' => null,
                'is_identity' => false,
                'comment' => null,
            ],
            (object) [
                'name' => 'is_admin',
                'data_type' => 'bit',
                'is_nullable' => 'NO',
                'column_default' => '((0))',
                'character_maximum_length' => null,
                'collation_name' => null,
                'is_identity' => false,
                'comment' => null,
            ],
        ]);

    DB::shouldReceive('connection')
        ->with('test_sqlsrv')
        ->andReturn($connectionMock);

    $introspector = new SchemaIntrospector('test_sqlsrv');

    $ref = new ReflectionMethod(SchemaIntrospector::class, 'getSqlServerColumns');
    $ref->setAccessible(true);
    $columns = $ref->invoke($introspector, 'users');

    expect($columns)->toHaveCount(4)
        ->and($columns[0]['name'])->toBe('id')
        ->and($columns[0]['type'])->toBe('bigint')
        ->and($columns[0]['auto_increment'])->toBeTrue()
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

it('introspects sql server indexes correctly', function () {
    Config::set('database.connections.test_sqlsrv', [
        'driver' => 'sqlsrv',
    ]);

    $connectionMock = Mockery::mock(Connection::class);

    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT.*i\.name AS index_name/s'), ['users'])
        ->andReturn([
            (object) [
                'index_name' => 'PK_users',
                'is_unique' => true,
                'is_primary_key' => true,
                'column_name' => 'id',
            ],
            (object) [
                'index_name' => 'UQ_users_email',
                'is_unique' => true,
                'is_primary_key' => false,
                'column_name' => 'email',
            ],
        ]);

    DB::shouldReceive('connection')
        ->with('test_sqlsrv')
        ->andReturn($connectionMock);

    $introspector = new SchemaIntrospector('test_sqlsrv');

    $ref = new ReflectionMethod(SchemaIntrospector::class, 'getSqlServerIndexes');
    $ref->setAccessible(true);
    $indexes = $ref->invoke($introspector, 'users');

    expect($indexes)->toHaveCount(2)
        ->and($indexes[0]['key_name'])->toBe('PK_users')
        ->and($indexes[0]['index_type'])->toBe('primary')
        ->and($indexes[0]['columns'])->toBe(['id'])
        ->and($indexes[1]['key_name'])->toBe('UQ_users_email')
        ->and($indexes[1]['index_type'])->toBe('unique')
        ->and($indexes[1]['columns'])->toBe(['email']);
});

it('introspects sql server foreign keys correctly', function () {
    Config::set('database.connections.test_sqlsrv', [
        'driver' => 'sqlsrv',
    ]);

    $connectionMock = Mockery::mock(Connection::class);

    $connectionMock->shouldReceive('select')
        ->with(Mockery::pattern('/SELECT.*fk\.name AS constraint_name/s'), ['posts'])
        ->andReturn([
            (object) [
                'constraint_name' => 'FK_posts_users',
                'column_name' => 'user_id',
                'referenced_table' => 'users',
                'referenced_column' => 'id',
                'on_update' => 'NO_ACTION',
                'on_delete' => 'CASCADE',
            ],
        ]);

    DB::shouldReceive('connection')
        ->with('test_sqlsrv')
        ->andReturn($connectionMock);

    $introspector = new SchemaIntrospector('test_sqlsrv');

    $ref = new ReflectionMethod(SchemaIntrospector::class, 'getSqlServerForeignKeys');
    $ref->setAccessible(true);
    $fks = $ref->invoke($introspector, 'posts');

    expect($fks)->toHaveCount(1)
        ->and($fks[0]['name'])->toBe('FK_posts_users')
        ->and($fks[0]['column'])->toBe('user_id')
        ->and($fks[0]['referenced_table'])->toBe('users')
        ->and($fks[0]['referenced_column'])->toBe('id')
        ->and($fks[0]['on_update'])->toBeNull()
        ->and($fks[0]['on_delete'])->toBe('CASCADE');
});
