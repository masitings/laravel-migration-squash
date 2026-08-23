<?php

use MigrationSquash\Verification\SchemaDiff;

test('SchemaDiff add stores details as array, not compact spread', function () {
    $diff = new SchemaDiff;

    // This was C-6: compact('table', 'type', ...$details) caused ArgumentCountError
    $diff->add('users', 'column_missing', ['column' => 'email']);

    expect($diff->differences)->toHaveCount(1)
        ->and($diff->differences[0])->toBe([
            'table' => 'users',
            'type' => 'column_missing',
            'details' => ['column' => 'email'],
        ]);
});

test('SchemaDiff add works with empty details', function () {
    $diff = new SchemaDiff;

    $diff->add('posts', 'table_missing', []);

    expect($diff->differences[0]['details'])->toBeEmpty();
});

test('SchemaDiff isEmpty returns true when no differences', function () {
    $diff = new SchemaDiff;

    expect($diff->isEmpty())->toBeTrue();

    $diff->add('users', 'table_missing', []);

    expect($diff->isEmpty())->toBeFalse();
});

test('SchemaDiff formatMessage reads from details key', function () {
    $diff = new SchemaDiff;

    $diff->add('users', 'column_missing', ['column' => 'email']);
    $diff->add('users', 'column_type_mismatch', [
        'column' => 'name',
        'expected' => 'varchar',
        'actual' => 'text',
    ]);

    $message = $diff->formatMessage();

    expect($message)->toContain("Column 'email' missing")
        ->and($message)->toContain('name type mismatch')
        ->and($message)->toContain('expected varchar')
        ->and($message)->toContain('got text');
});

test('SchemaDiff formatMessage handles all diff types', function () {
    $diff = new SchemaDiff;

    $diff->add('posts', 'table_missing', []);
    $diff->add('cache', 'extra_table', []);
    $diff->add('users', 'index_missing', ['columns' => 'email', 'type' => 'unique']);
    $diff->add('users', 'foreign_key_missing', ['column' => 'user_id', 'referenced_table' => 'users']);

    $message = $diff->formatMessage();

    expect($message)->toContain('Table missing')
        ->and($message)->toContain('Extra table')
        ->and($message)->toContain('Index missing')
        ->and($message)->toContain('Foreign key missing');
});
