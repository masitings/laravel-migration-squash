<?php

/**
 * Feature-level regression tests for the two defects that could corrupt a
 * user's migration folder:
 *
 *  Fix #1 — nothing is written or moved until the user confirms
 *  Fix #2 — generated migrations must sort BEFORE any migration left behind
 */

use Illuminate\Support\Facades\Schema;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;

/**
 * Build a throwaway Laravel database path containing the given migration files.
 *
 * @param  array<string, string>  $files  filename => file body
 */
function makeMigrationWorkspace(array $files): string
{
    $root = sys_get_temp_dir().'/squash-ws-'.uniqid();
    mkdir($root.'/migrations', 0755, true);

    foreach ($files as $filename => $body) {
        file_put_contents($root.'/migrations/'.$filename, $body);
    }

    return $root;
}

function removeWorkspace(string $root): void
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

function usersMigration(): string
{
    return <<<'PHP'
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
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
PHP;
}

test('declining the confirmation writes nothing and moves nothing', function () {
    $root = makeMigrationWorkspace([
        '2024_01_01_000001_create_users_table.php' => usersMigration(),
    ]);

    $this->app->useDatabasePath($root);

    $before = scandir($root.'/migrations');

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'no')
        ->assertExitCode(0);

    $after = scandir($root.'/migrations');

    removeWorkspace($root);

    expect($after)->toEqual($before);
});

test('accepting the confirmation archives originals and writes squashed files', function () {
    $root = makeMigrationWorkspace([
        '2024_01_01_000001_create_users_table.php' => usersMigration(),
    ]);

    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));
    $archived = glob($root.'/migrations-archive/*/*.php');

    removeWorkspace($root);

    expect($remaining)->not->toContain('2024_01_01_000001_create_users_table.php')
        ->and($remaining)->toHaveCount(1)
        ->and($archived)->toHaveCount(1);
});

test('generated migrations sort before a guarded migration that stays behind', function () {
    $guarded = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD FULLTEXT(email)');
    }

    public function down(): void {}
};
PHP;

    $root = makeMigrationWorkspace([
        '2024_01_01_000001_create_users_table.php' => usersMigration(),
        '2024_06_01_000002_add_fulltext_to_users.php' => $guarded,
    ]);

    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));
    sort($remaining);

    removeWorkspace($root);

    // The guarded file keeps its original name and is still present.
    expect($remaining)->toContain('2024_06_01_000002_add_fulltext_to_users.php');

    $generated = array_values(array_filter(
        $remaining,
        fn ($f) => $f !== '2024_06_01_000002_add_fulltext_to_users.php',
    ));

    expect($generated)->not->toBeEmpty();

    // Laravel runs migrations in filename order, so every generated file must
    // sort strictly before the guarded one that targets the table it creates.
    foreach ($generated as $file) {
        expect(strcmp($file, '2024_06_01_000002_add_fulltext_to_users.php'))
            ->toBeLessThan(0, "{$file} must sort before the guarded migration");
    }
});

test('enum allowed values survive a SQLite introspection round trip', function () {
    $sandboxName = SandboxConnectionFactory::create('sqlite');

    try {
        Schema::connection($sandboxName)->create('articles', function ($table) {
            $table->id();
            $table->enum('status', ['draft', 'published', 'archived']);
        });

        $snapshot = (new SchemaIntrospector($sandboxName))->getSnapshot();

        $status = collect($snapshot['tables']['articles']['columns'])
            ->firstWhere('name', 'status');

        expect($status['type'])->toBe('enum')
            ->and($status['allowed_values'])->toBe(['draft', 'published', 'archived']);
    } finally {
        SandboxConnectionFactory::destroy($sandboxName);
    }
});
