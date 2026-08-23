<?php

/**
 * `--table` narrows the squash to specific tables. Two things can go wrong:
 *
 *  1. A squashed table can hold a foreign key pointing at a table that was
 *     filtered out, so the generated FK migration references something that
 *     does not exist in the squashed set.
 *  2. Migrations that were filtered out keep their original timestamps. The
 *     generated files must still sort before them, which means the base
 *     timestamp has to come from ALL migrations, not just the filtered ones.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use MigrationSquash\Archiving\MigrationArchiver;
use Symfony\Component\Console\Output\BufferedOutput;

function filterWorkspace(): string
{
    $root = sys_get_temp_dir().'/squash-filter-'.uniqid();
    mkdir($root.'/migrations', 0755, true);

    file_put_contents($root.'/migrations/2024_01_01_000001_create_teams_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
PHP);

    file_put_contents($root.'/migrations/2024_02_01_000002_create_members_table.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('email');
            $table->foreign('team_id')->references('id')->on('teams');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
PHP);

    return $root;
}

function cleanupFilterWorkspace(string $root): void
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

/**
 * Run migrate:squash and capture both the exit code and the real output.
 *
 * Using $this->artisan() without expectsConfirmation() makes the command fail
 * on the unanswered prompt, which looks like a refusal but is not one. This
 * runs the command for real so a test cannot pass for the wrong reason.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{status: int, output: string}
 */
function runSquash(Application $app, array $parameters): array
{
    $output = new BufferedOutput;

    $status = $app[Kernel::class]->call('migrate:squash', $parameters, $output);

    return ['status' => $status, 'output' => $output->fetch()];
}

test('--table refuses when a squashed table has a foreign key to a filtered-out table', function () {
    $root = filterWorkspace();
    $this->app->useDatabasePath($root);

    // Squash only "members", which points a foreign key at "teams".
    $result = runSquash($this->app, ['--table' => ['members']]);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));
    sort($remaining);

    $archiveExists = is_dir($root.'/migrations-archive');

    cleanupFilterWorkspace($root);

    expect($result['status'])->toBe(1, $result['output'])
        ->and($result['output'])->toContain('not self-contained')
        ->and($result['output'])->toContain('members.team_id → teams')
        // Nothing generated, nothing archived, both originals untouched.
        ->and($remaining)->toBe([
            '2024_01_01_000001_create_teams_table.php',
            '2024_02_01_000002_create_members_table.php',
        ])
        ->and($archiveExists)->toBeFalse();
});

test('squashing everything is self-contained and succeeds', function () {
    $root = filterWorkspace();
    $this->app->useDatabasePath($root);

    $result = runSquash($this->app, ['--dry-run' => true]);

    cleanupFilterWorkspace($root);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])->not->toContain('not self-contained')
        ->and($result['output'])->toContain('Schema verification passed');
});

test('--table works when the filtered set is self-contained', function () {
    $root = filterWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash --table=teams')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));
    sort($remaining);

    $archived = glob($root.'/migrations-archive/*/*.php');

    cleanupFilterWorkspace($root);

    // The teams migration was archived, members was left completely alone.
    expect($archived)->toHaveCount(1)
        ->and(basename($archived[0]))->toBe('2024_01_01_000001_create_teams_table.php')
        ->and($remaining)->toContain('2024_02_01_000002_create_members_table.php');
});

test('generated files sort before migrations that --table filtered out', function () {
    $root = filterWorkspace();
    $this->app->useDatabasePath($root);

    $this->artisan('migrate:squash --table=teams')
        ->expectsConfirmation('Continue?', 'yes')
        ->assertExitCode(0);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));

    cleanupFilterWorkspace($root);

    $generated = array_values(array_filter(
        $remaining,
        fn ($f) => $f !== '2024_02_01_000002_create_members_table.php',
    ));

    expect($generated)->not->toBeEmpty();

    // The members migration was NOT squashed and keeps its 2024_02_01
    // timestamp. Every generated file must still run before it, because
    // members holds a foreign key onto the table they create.
    foreach ($generated as $file) {
        expect(strcmp($file, '2024_02_01_000002_create_members_table.php'))
            ->toBeLessThan(0, "{$file} must sort before the filtered-out migration");
    }
});

test('archiver preview reports the archive root it will actually use', function () {
    $root = filterWorkspace();
    $this->app->useDatabasePath($root);

    $archiver = new MigrationArchiver;

    expect($archiver->getArchiveRoot())->toBe($root.'/migrations-archive')
        ->and($archiver->getArchivePath())->toStartWith($root.'/migrations-archive/');

    cleanupFilterWorkspace($root);
});
