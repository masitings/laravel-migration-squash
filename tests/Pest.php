<?php

use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Verification\SchemaComparator;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * Assert that two schemas are identical.
 *
 * Accepts either a snapshot array (as returned by
 * SchemaIntrospector::getSnapshot()) or a sandbox connection name on both
 * sides, so a test can compare two live databases, two snapshots, or one of
 * each:
 *
 *     expect($generatedConnection)->toMatchSchema($originalSnapshot);
 *     expect($afterSnapshot)->toMatchSchema($beforeSnapshot);
 *
 * On failure the message is the full human-readable diff from SchemaDiff,
 * not just "false is not true".
 */
expect()->extend('toMatchSchema', function (array|string $expected) {
    $actualSnapshot = normalizeSchemaSnapshot($this->value);
    $expectedSnapshot = normalizeSchemaSnapshot($expected);

    $diff = (new SchemaComparator)->compare($expectedSnapshot, $actualSnapshot);

    expect($diff->isEmpty())->toBeTrue(
        "Schemas do not match.\n\n".$diff->formatMessage()
    );

    return $this;
});

/**
 * Assert that two schemas differ, optionally checking the diff types found.
 *
 *     expect($after)->toDifferFromSchema($before, ['column_missing']);
 *
 * @param  array<int, string>  $expectedTypes  Diff types that must be present
 */
expect()->extend('toDifferFromSchema', function (array|string $expected, array $expectedTypes = []) {
    $actualSnapshot = normalizeSchemaSnapshot($this->value);
    $expectedSnapshot = normalizeSchemaSnapshot($expected);

    $diff = (new SchemaComparator)->compare($expectedSnapshot, $actualSnapshot);

    expect($diff->isEmpty())->toBeFalse('Expected the schemas to differ, but they are identical.');

    $foundTypes = array_column($diff->serialize(), 'type');

    // Checked with toBeTrue rather than toContain so the failure message
    // survives; toContain treats extra arguments as further needles.
    foreach ($expectedTypes as $type) {
        expect(in_array($type, $foundTypes, true))->toBeTrue(
            "Expected a '{$type}' difference. Got: ".implode(', ', array_unique($foundTypes))
        );
    }

    return $this;
});

/**
 * Turn a connection name into a snapshot; pass snapshots through untouched.
 *
 * @param  array<string, mixed>|string  $value
 * @return array{tables: array<string, array<string, mixed>>}
 */
function normalizeSchemaSnapshot(array|string $value): array
{
    if (is_string($value)) {
        return (new SchemaIntrospector($value))->getSnapshot();
    }

    if (! array_key_exists('tables', $value)) {
        throw new InvalidArgumentException(
            'Expected a schema snapshot with a "tables" key, or a connection name.'
        );
    }

    return $value;
}

/**
 * Is a MySQL server reachable with the configured credentials?
 */
function mysqlAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        new PDO(
            sprintf(
                'mysql:host=%s;port=%s',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
            ),
            env('DB_USERNAME', 'root'),
            env('DB_PASSWORD', ''),
            [PDO::ATTR_TIMEOUT => 3],
        );

        $available = true;
    } catch (Throwable $e) {
        $available = false;
    }

    return $available;
}

/**
 * Is a PostgreSQL server reachable with the configured credentials?
 */
function postgresAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=postgres',
                env('PGSQL_HOST', env('DB_HOST', '127.0.0.1')),
                env('PGSQL_PORT', env('DB_PORT', '5432')),
            ),
            env('PGSQL_USERNAME', env('DB_USERNAME', 'postgres')),
            env('PGSQL_PASSWORD', env('DB_PASSWORD', '')),
            [PDO::ATTR_TIMEOUT => 3],
        );

        $available = true;
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}

/**
 * Is a SQL Server reachable with the configured credentials?
 */
function sqlServerAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    try {
        new PDO(
            sprintf(
                'sqlsrv:Server=%s,%s;Database=master',
                env('SQLSRV_HOST', env('DB_HOST', '127.0.0.1')),
                env('SQLSRV_PORT', env('DB_PORT', '1433')),
            ),
            env('SQLSRV_USERNAME', env('DB_USERNAME', 'sa')),
            env('SQLSRV_PASSWORD', env('DB_PASSWORD', '')),
            [PDO::ATTR_TIMEOUT => 3],
        );

        $available = true;
    } catch (Throwable) {
        $available = false;
    }

    return $available;
}
