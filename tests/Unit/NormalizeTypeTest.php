<?php

use MigrationSquash\Introspection\SchemaIntrospector;

it('normalizes postgres and sql server types correctly', function (string $rawType, string $expected) {
    $introspector = new SchemaIntrospector('sqlite');
    $ref = new ReflectionMethod(SchemaIntrospector::class, 'normalizeType');
    $ref->setAccessible(true);

    $actual = $ref->invoke($introspector, $rawType);

    expect($actual)->toBe($expected);
})->with([
    // Postgres types
    ['character varying(255)', 'varchar'],
    ['character varying', 'varchar'],
    ['character(10)', 'char'],
    ['int2', 'smallint'],
    ['int4', 'integer'],
    ['int8', 'bigint'],
    ['float4', 'float'],
    ['float8', 'double'],
    ['bool', 'boolean'],
    ['boolean', 'boolean'],
    ['timestamptz', 'timestamp'],
    ['timestamp without time zone', 'timestamp'],
    ['timestamp with time zone', 'timestamp'],
    ['timestamp(6) without time zone', 'timestamp'],
    ['bytea', 'blob'],
    ['jsonb', 'json'],
    ['uuid', 'uuid'],
    ['serial', 'integer'],
    ['bigserial', 'bigint'],
    ['smallserial', 'smallint'],

    // SQL Server types
    ['nvarchar(255)', 'varchar'],
    ['nvarchar(max)', 'varchar'],
    ['nchar(10)', 'char'],
    ['ntext', 'text'],
    ['bit', 'boolean'],
    ['datetime2', 'datetime'],
    ['datetime2(7)', 'datetime'],
    ['datetimeoffset', 'timestamp'],
    ['smalldatetime', 'datetime'],
    ['money', 'decimal'],
    ['smallmoney', 'decimal'],
    ['uniqueidentifier', 'uuid'],
    ['image', 'blob'],
    ['varbinary(max)', 'binary'],
    ['varbinary(255)', 'binary'],
    ['real', 'float'],
    ['tinyint', 'tinyint'],
]);
