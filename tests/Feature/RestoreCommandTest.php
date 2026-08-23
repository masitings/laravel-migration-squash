<?php

/**
 * FR-5.4 — `migrate:squash:restore` puts an archived migration history back,
 * verifying every file against the hash recorded in the manifest first.
 *
 * FR-6.4 — the toMatchSchema() expectation is exercised here too: the schema
 * produced by the restored migrations must match the one the squash captured.
 */

use Illuminate\Support\Facades\Schema;
use MigrationSquash\Archiving\MigrationArchiver;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;

function restoreWorkspace(): string
{
    $root = sys_get_temp_dir().'/squash-restore-'.uniqid();
    mkdir($root.'/migrations', 0755, true);

    file_put_contents($root.'/migrations/2024_01_01_000001_create_users_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
PHP);

    return $root;
}

function cleanupRestoreWorkspace(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($root);
}

test('restore puts the original migrations back and removes the squashed ones', function () {
    $root = restoreWorkspace();
    $this->app->useDatabasePath($root);

    $original = file_get_contents($root.'/migrations/2024_01_01_000001_create_users_table.php');

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    // The original is gone, a squashed file is in its place.
    expect(file_exists($root.'/migrations/2024_01_01_000001_create_users_table.php'))->toBeFalse();

    $this->artisan('migrate:squash:restore')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $restored = $root.'/migrations/2024_01_01_000001_create_users_table.php';
    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));

    $restoredContent = file_exists($restored) ? file_get_contents($restored) : null;

    cleanupRestoreWorkspace($root);

    expect($restoredContent)->toBe($original)
        ->and($remaining)->toBe(['2024_01_01_000001_create_users_table.php']);
});

test('restore keeps the squashed migrations when asked', function () {
    $root = restoreWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $this->artisan('migrate:squash:restore --keep-squashed')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));

    cleanupRestoreWorkspace($root);

    expect($remaining)->toHaveCount(2);
});

test('restore refuses when an archived file no longer matches its manifest hash', function () {
    $root = restoreWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    // Tamper with the archived copy.
    $archived = glob($root.'/migrations-archive/*/2024_01_01_000001_create_users_table.php')[0];
    file_put_contents($archived, "<?php // tampered\n");

    $this->artisan('migrate:squash:restore')->assertExitCode(1);

    $restoredExists = file_exists($root.'/migrations/2024_01_01_000001_create_users_table.php');

    cleanupRestoreWorkspace($root);

    expect($restoredExists)->toBeFalse();
});

test('restore --list shows the available archives without changing anything', function () {
    $root = restoreWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $before = scandir($root.'/migrations');

    $this->artisan('migrate:squash:restore --list')->assertExitCode(0);

    $after = scandir($root.'/migrations');

    cleanupRestoreWorkspace($root);

    expect($after)->toEqual($before);
});

test('restore fails cleanly when there is nothing to restore', function () {
    $root = restoreWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash:restore')->assertExitCode(1);

    cleanupRestoreWorkspace($root);
});

test('archiver lists archives and reads their manifests', function () {
    $root = restoreWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $archiver = new MigrationArchiver;
    $archives = $archiver->listArchives();
    $manifest = $archiver->readManifest($archives[0]);

    cleanupRestoreWorkspace($root);

    expect($archives)->toHaveCount(1)
        ->and($manifest)->not->toBeNull()
        ->and($manifest['files'])->toHaveCount(1)
        ->and($manifest['files'][0]['filename'])->toBe('2024_01_01_000001_create_users_table.php');
});

test('the schema from restored migrations matches the schema the squash captured', function () {
    $sandbox = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($sandbox)->create('users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $snapshot = (new SchemaIntrospector($sandbox))->getSnapshot();

        // The custom expectation accepts a connection name on either side.
        expect($sandbox)->toMatchSchema($snapshot);
    } finally {
        SandboxConnectionFactory::destroy($sandbox);
    }
});

test('toMatchSchema reports a readable diff when the schemas differ', function () {
    $a = SandboxConnectionFactory::create('sqlite');
    $b = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($a)->create('users', function ($table) {
            $table->id();
            $table->string('email');
        });

        Schema::connection($b)->create('users', function ($table) {
            $table->id();
        });

        expect($b)->toDifferFromSchema($a, ['column_missing']);

        try {
            expect($b)->toMatchSchema($a);
            $message = null;
        } catch (Throwable $e) {
            $message = $e->getMessage();
        }

        expect($message)->not->toBeNull()
            ->and($message)->toContain('Schemas do not match')
            ->and($message)->toContain('email');
    } finally {
        SandboxConnectionFactory::destroy($a);
        SandboxConnectionFactory::destroy($b);
    }
});

test('toMatchSchema rejects a value that is neither a snapshot nor a connection', function () {
    expect(fn () => expect(['not' => 'a snapshot'])->toMatchSchema(['tables' => []]))
        ->toThrow(InvalidArgumentException::class);
});
