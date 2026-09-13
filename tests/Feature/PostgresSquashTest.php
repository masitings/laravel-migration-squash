<?php

/**
 * Coverage for the PostgreSQL sandbox path.
 * Skips itself if PostgreSQL server is not reachable.
 */

use Illuminate\Support\Facades\DB;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;

beforeEach(function () {
    if (! postgresAvailable()) {
        $this->markTestSkipped('No PostgreSQL server reachable; set PGSQL_HOST/PGSQL_USERNAME/PGSQL_PASSWORD to run these.');
    }
});

test('PostgreSQL sandbox creates a real database that can be queried', function () {
    $name = SandboxConnectionFactory::create('pgsql');

    try {
        $database = config("database.connections.{$name}.database");

        expect($database)->toStartWith(SandboxConnectionFactory::SANDBOX_DB_PREFIX);

        $selected = DB::connection($name)->select('SELECT current_database() as db')[0]->db;

        expect($selected)->toBe($database);
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});

test('destroy drops the PostgreSQL sandbox database', function () {
    $name = SandboxConnectionFactory::create('pgsql');
    $database = config("database.connections.{$name}.database");

    SandboxConnectionFactory::destroy($name);

    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=postgres', env('PGSQL_HOST', env('DB_HOST', '127.0.0.1')), env('PGSQL_PORT', env('DB_PORT', '5432'))),
        env('PGSQL_USERNAME', env('DB_USERNAME', 'postgres')),
        env('PGSQL_PASSWORD', env('DB_PASSWORD', '')),
    );

    $rows = $pdo->query(
        'SELECT datname FROM pg_database WHERE datname = '.$pdo->quote($database)
    )->fetchAll();

    expect($rows)->toBeEmpty();
});

test('SandboxRunner fresh drops all tables on PostgreSQL', function () {
    $name = SandboxConnectionFactory::create('pgsql');

    try {
        DB::connection($name)->statement('CREATE TABLE users (id SERIAL PRIMARY KEY, name VARCHAR(255))');
        DB::connection($name)->statement('CREATE TABLE posts (id SERIAL PRIMARY KEY, user_id INT REFERENCES users(id))');

        $runner = new SandboxRunner($name);
        $runner->fresh();

        $tables = DB::connection($name)->select("
            SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
        ");

        expect($tables)->toBeEmpty();
    } finally {
        SandboxConnectionFactory::destroy($name);
    }
});
