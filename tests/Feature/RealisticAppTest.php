<?php

/**
 * The closest thing to running this on a real application that a test suite
 * can get to.
 *
 * Every other test uses one or two hand-written fixtures. This one builds a
 * migration history shaped like a real Laravel app that has been alive for a
 * while: the framework's own tables, a domain on top, and then the usual drift
 * of columns added, made nullable, renamed away, indexed later, and foreign
 * keys attached after the fact.
 *
 * It then does the whole thing for real, and finishes with the check that
 * actually matters: the squashed migrations are run from an empty database and
 * the resulting schema is compared against the original history's schema.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use MigrationSquash\Introspection\SchemaIntrospector;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Sandbox\SandboxRunner;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A migration history with the shape a real app accumulates.
 *
 * @return array<string, string> filename => body
 */
function realisticMigrationHistory(): array
{
    $wrap = fn (string $up) => <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
{$up}
    }

    public function down(): void
    {
        // not exercised by squashing
    }
};
PHP;

    return [
        // --- framework tables ---
        '2019_08_19_000000_create_users_table.php' => $wrap(<<<'PHP'
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
PHP),

        '2019_08_19_000001_create_password_reset_tokens_table.php' => $wrap(<<<'PHP'
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
PHP),

        '2019_08_19_000002_create_jobs_table.php' => $wrap(<<<'PHP'
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
PHP),

        // --- domain ---
        '2020_01_10_000000_create_teams_table.php' => $wrap(<<<'PHP'
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->timestamps();
        });
PHP),

        '2020_01_10_000001_create_projects_table.php' => $wrap(<<<'PHP'
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamps();
        });
PHP),

        '2020_03_02_000000_create_project_user_table.php' => $wrap(<<<'PHP'
        Schema::create('project_user', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->primary(['project_id', 'user_id']);
        });
PHP),

        // --- the drift that makes squashing worth doing ---
        '2020_06_15_000000_add_team_id_to_users.php' => $wrap(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('id');
        });
PHP),

        '2020_09_01_000000_add_status_to_projects.php' => $wrap(<<<'PHP'
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
        });
PHP),

        '2021_02_11_000000_add_avatar_and_bio_to_users.php' => $wrap(<<<'PHP'
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable();
            $table->text('bio')->nullable();
        });
PHP),

        '2021_05_20_000000_add_indexes_to_projects.php' => $wrap(<<<'PHP'
        Schema::table('projects', function (Blueprint $table) {
            $table->index('team_id');
            $table->index(['team_id', 'status']);
        });
PHP),

        '2021_11_03_000000_add_foreign_keys.php' => $wrap(<<<'PHP'
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams')->onDelete('CASCADE');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('teams');
        });

        Schema::table('project_user', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('CASCADE');
        });
PHP),

        '2022_04_18_000000_create_comments_table.php' => $wrap(<<<'PHP'
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->index('project_id');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('CASCADE');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('parent_id')->references('id')->on('comments')->onDelete('CASCADE');
        });
PHP),

        '2023_01_09_000000_add_settings_to_teams.php' => $wrap(<<<'PHP'
        Schema::table('teams', function (Blueprint $table) {
            $table->json('settings')->nullable();
            $table->unsignedInteger('seat_limit')->default(5);
        });
PHP),

        '2023_07_22_000000_make_project_description_required.php' => $wrap(<<<'PHP'
        Schema::table('projects', function (Blueprint $table) {
            $table->string('slug')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unique('slug');
        });
PHP),

        '2024_02_14_000000_create_activity_log_table.php' => $wrap(<<<'PHP'
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('event', 60);
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
PHP),
    ];
}

function realisticWorkspace(): string
{
    $root = sys_get_temp_dir().'/squash-real-'.uniqid();
    mkdir($root.'/migrations', 0755, true);

    foreach (realisticMigrationHistory() as $filename => $body) {
        file_put_contents($root.'/migrations/'.$filename, $body);
    }

    return $root;
}

function removeRealisticWorkspace(string $root): void
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
 * @return array{status: int, output: string}
 */
function runRealSquash(Application $app, array $parameters): array
{
    $output = new BufferedOutput;
    $status = $app[Kernel::class]->call('migrate:squash', $parameters, $output);

    return ['status' => $status, 'output' => $output->fetch()];
}

test('dry run on a realistic 15-migration history reports an empty diff', function () {
    $root = realisticWorkspace();
    $this->app->useDatabasePath($root);

    $result = runRealSquash($this->app, ['--dry-run' => true]);

    $remaining = array_values(array_diff(scandir($root.'/migrations'), ['.', '..']));

    removeRealisticWorkspace($root);

    expect($result['status'])->toBe(0, $result['output'])
        ->and($result['output'])->toContain('Schema verification passed')
        ->and($result['output'])->toContain('Dry run completed successfully')
        // --dry-run must not touch a single file.
        ->and($remaining)->toHaveCount(count(realisticMigrationHistory()));
});

test('the squashed migrations of a realistic history actually migrate from scratch', function () {
    $root = realisticWorkspace();
    $this->app->useDatabasePath($root);

    // 1. What the original 15 migrations produce.
    $original = SandboxConnectionFactory::create('sqlite');
    (new SandboxRunner($original))->run(glob($root.'/migrations/*.php'));
    $before = (new SchemaIntrospector($original))->getSnapshot();

    expect($before['tables'])->toHaveKeys([
        'users', 'teams', 'projects', 'project_user', 'comments', 'activity_log', 'jobs',
    ]);

    // 2. Squash for real.
    $result = runRealSquash($this->app, []);

    $squashed = glob($root.'/migrations/*.php');
    $archived = glob($root.'/migrations-archive/*/*.php');

    // 3. Run ONLY the squashed output against an empty database.
    $replay = SandboxConnectionFactory::create('sqlite');
    (new SandboxRunner($replay))->run($squashed);
    $after = (new SchemaIntrospector($replay))->getSnapshot();

    $originalCount = count(realisticMigrationHistory());

    removeRealisticWorkspace($root);
    SandboxConnectionFactory::destroy($original);
    SandboxConnectionFactory::destroy($replay);

    expect($result['status'])->toBe(0, $result['output'])
        // 15 migrations collapsed to one file per table plus the FK file.
        ->and(count($squashed))->toBeLessThan($originalCount)
        ->and($archived)->toHaveCount($originalCount)
        // The whole point: same schema, fewer files.
        ->and($after)->toMatchSchema($before);
});

test('a realistic history squashes identically on MySQL', function () {
    if (! mysqlAvailable()) {
        $this->markTestSkipped('No MySQL server reachable.');
    }

    $root = realisticWorkspace();
    $this->app->useDatabasePath($root);

    $original = SandboxConnectionFactory::create('mysql');
    $replay = SandboxConnectionFactory::create('mysql');

    try {
        (new SandboxRunner($original))->run(glob($root.'/migrations/*.php'));
        $before = (new SchemaIntrospector($original))->getSnapshot();

        $result = runRealSquash($this->app, ['--driver' => 'mysql']);

        expect($result['status'])->toBe(0, $result['output']);

        (new SandboxRunner($replay))->run(glob($root.'/migrations/*.php'));
        $after = (new SchemaIntrospector($replay))->getSnapshot();

        expect($after)->toMatchSchema($before);
    } finally {
        removeRealisticWorkspace($root);
        SandboxConnectionFactory::destroy($original);
        SandboxConnectionFactory::destroy($replay);
    }
});

test('restoring a realistic history brings back every original file byte for byte', function () {
    $root = realisticWorkspace();
    $this->app->useDatabasePath($root);

    $originals = [];

    foreach (glob($root.'/migrations/*.php') as $file) {
        $originals[basename($file)] = file_get_contents($file);
    }

    runRealSquash($this->app, []);

    $output = new BufferedOutput;
    $restoreStatus = $this->app[Kernel::class]->call('migrate:squash:restore', [], $output);

    $restored = [];

    foreach (glob($root.'/migrations/*.php') as $file) {
        $restored[basename($file)] = file_get_contents($file);
    }

    removeRealisticWorkspace($root);

    expect($restoreStatus)->toBe(0, $output->fetch())
        ->and($restored)->toEqual($originals);
});
