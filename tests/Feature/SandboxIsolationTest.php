<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;

test('sandbox connection name starts with squash-sandbox-', function () {
    $name = SandboxConnectionFactory::create('sqlite');

    expect($name)->toStartWith('squash-sandbox-');

    SandboxConnectionFactory::destroy($name);
});

test('sandbox connection is registered in Laravel config', function () {
    $name = SandboxConnectionFactory::create('sqlite');

    $config = config("database.connections.{$name}");

    expect($config)->not->toBeNull()
        ->and($config['driver'])->toBe('sqlite')
        ->and($config['database'])->toBe(':memory:');

    SandboxConnectionFactory::destroy($name);
});

test('sandbox does not touch default connection', function () {
    // Create a marker table on the default/testing connection
    Schema::connection('testing')->create('_isolation_test_marker', function ($table) {
        $table->id();
        $table->string('proof', 50)->default('untouched');
    });

    DB::connection('testing')->table('_isolation_test_marker')->insert(['proof' => 'original']);

    // Create sandbox and do work there
    $sandboxName = SandboxConnectionFactory::create('sqlite');
    $runner = new SandboxRunner($sandboxName);
    $runner->fresh();

    // Create a table in the sandbox
    Schema::connection($sandboxName)->create('sandbox_only_table', function ($table) {
        $table->id();
        $table->string('name');
    });

    // Verify: sandbox has the sandbox table
    expect(Schema::connection($sandboxName)->hasTable('sandbox_only_table'))->toBeTrue();

    // Verify: default connection still has the marker and its data is untouched
    expect(Schema::connection('testing')->hasTable('_isolation_test_marker'))->toBeTrue();

    $marker = DB::connection('testing')->table('_isolation_test_marker')->first();
    expect($marker->proof)->toBe('original');

    // Verify: default connection does NOT have the sandbox table
    expect(Schema::connection('testing')->hasTable('sandbox_only_table'))->toBeFalse();

    // Cleanup
    SandboxConnectionFactory::destroy($sandboxName);
    Schema::connection('testing')->dropIfExists('_isolation_test_marker');
});

test('SandboxRunner rejects connection names without squash-sandbox- prefix', function () {
    expect(fn () => new SandboxRunner('mysql'))
        ->toThrow(RuntimeException::class, 'Sandbox safety check failed');
});

test('SandboxRunner rejects default connection name', function () {
    expect(fn () => new SandboxRunner('sqlite'))
        ->toThrow(RuntimeException::class, 'Sandbox safety check failed');
});

test('SandboxRunner fresh resets SQLite in-memory database', function () {
    $name = SandboxConnectionFactory::create('sqlite');
    $runner = new SandboxRunner($name);

    // Create a table
    Schema::connection($name)->create('temp_table', function ($table) {
        $table->id();
    });

    expect(Schema::connection($name)->hasTable('temp_table'))->toBeTrue();

    // Fresh should remove it
    $runner->fresh();

    expect(Schema::connection($name)->hasTable('temp_table'))->toBeFalse();

    SandboxConnectionFactory::destroy($name);
});

test('SandboxConnectionFactory destroy removes config and disconnects', function () {
    $name = SandboxConnectionFactory::create('sqlite');

    expect(config("database.connections.{$name}"))->not->toBeNull();

    SandboxConnectionFactory::destroy($name);

    expect(config("database.connections.{$name}"))->toBeNull();
});
