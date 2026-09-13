<?php

/**
 * Coverage for the SQL Server sandbox path.
 * Skips itself if SQL Server is not reachable.
 */

use Illuminate\Support\Facades\DB;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;

beforeEach(function () {
    if (! sqlServerAvailable()) {
        $this->markTestSkipped('No SQL Server reachable; set SQLSRV_HOST/SQLSRV_USERNAME/SQLSRV_PASSWORD to run these.');
    }
});

test('SQL Server sandbox creates a real database that can be queried', function () {
    $name = SandboxConnectionFactory::create('sqlsrv');

    try {
        $database = config("database.connections.{$name}.database");

        expect($database)->toStartWith(SandboxConnectionFactory::SANDBOX_DB_PREFIX);

        $selected = DB::connection($name)->select('SELECT DB_NAME() as db')[0]->db;

        expect($selected)->toBe($database);
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});

test('destroy drops the SQL Server sandbox database', function () {
    $name = SandboxConnectionFactory::create('sqlsrv');
    $database = config("database.connections.{$name}.database");

    SandboxConnectionFactory::destroy($name);

    $pdo = new PDO(
        sprintf('sqlsrv:Server=%s,%s;Database=master', env('SQLSRV_HOST', env('DB_HOST', '127.0.0.1')), env('SQLSRV_PORT', env('DB_PORT', '1433'))),
        env('SQLSRV_USERNAME', env('DB_USERNAME', 'sa')),
        env('SQLSRV_PASSWORD', env('DB_PASSWORD', '')),
    );

    $rows = $pdo->query(
        'SELECT name FROM sys.databases WHERE name = '.$pdo->quote($database)
    )->fetchAll();

    expect($rows)->toBeEmpty();
});

test('SandboxRunner fresh drops all tables on SQL Server', function () {
    $name = SandboxConnectionFactory::create('sqlsrv');

    try {
        DB::connection($name)->statement('CREATE TABLE users (id INT IDENTITY(1,1) PRIMARY KEY, name VARCHAR(255))');
        DB::connection($name)->statement('CREATE TABLE posts (id INT IDENTITY(1,1) PRIMARY KEY, user_id INT FOREIGN KEY REFERENCES users(id))');

        $runner = new SandboxRunner($name);
        $runner->fresh();

        $tables = DB::connection($name)->select("
            SELECT name FROM sys.tables WHERE type = 'U'
        ");

        expect($tables)->toBeEmpty();
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});
