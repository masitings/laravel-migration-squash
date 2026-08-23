<?php

/**
 * Regression tests for the six defects found in the post-PRD review.
 *
 * Each test names the defect it locks down. If one of these fails, the
 * corresponding bug has come back.
 */

use MigrationSquash\Archiving\MigrationArchiver;
use MigrationSquash\Generation\SquashedMigrationGenerator;
use MigrationSquash\Guards\FileChecker;
use MigrationSquash\Sandbox\SandboxConnectionFactory;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\Table;
use MigrationSquash\Verification\SchemaComparator;

/*
|--------------------------------------------------------------------------
| Fix #3 — guards must not block DB::raw() or DB::select()
|--------------------------------------------------------------------------
*/

function writeTempMigration(string $body): string
{
    $path = sys_get_temp_dir().'/guard-fixture-'.uniqid().'.php';

    file_put_contents($path, <<<PHP
    <?php

    use Illuminate\\Database\\Migrations\\Migration;
    use Illuminate\\Database\\Schema\\Blueprint;
    use Illuminate\\Support\\Facades\\DB;
    use Illuminate\\Support\\Facades\\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
    {$body}
        }
    };
    PHP);

    return $path;
}

test('guard does not flag DB::raw() as raw SQL', function () {
    $file = writeTempMigration(<<<'PHP'
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });
PHP);

    $result = (new FileChecker)->scanMigration($file);
    unlink($file);

    expect($result['rawSql'])->toBeEmpty();
});

test('guard does not flag DB::select() as raw SQL', function () {
    $file = writeTempMigration(<<<'PHP'
        $rows = DB::select('select 1');
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
        });
PHP);

    $result = (new FileChecker)->scanMigration($file);
    unlink($file);

    expect($result['rawSql'])->toBeEmpty();
});

test('guard still flags DB::statement() and DB::unprepared()', function () {
    $file = writeTempMigration(<<<'PHP'
        DB::statement('ALTER TABLE posts ADD FULLTEXT(title)');
        DB::unprepared('CREATE TRIGGER t BEFORE INSERT ON posts FOR EACH ROW SET NEW.x = 1');
PHP);

    $result = (new FileChecker)->scanMigration($file);
    unlink($file);

    expect($result['rawSql'])->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Fix #4 — MySQL sandbox database name is prefixed so destroy() can guard
|--------------------------------------------------------------------------
*/

test('sandbox database prefix constant is defined and non-empty', function () {
    expect(SandboxConnectionFactory::SANDBOX_DB_PREFIX)
        ->toBeString()
        ->not->toBeEmpty();
});

test('destroy does not attempt a DROP DATABASE on a sqlite sandbox', function () {
    $name = SandboxConnectionFactory::create('sqlite');

    // Would throw if the code tried to run DROP DATABASE against SQLite.
    SandboxConnectionFactory::destroy($name);

    expect(config("database.connections.{$name}"))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Fix #5 — archive directory must never sit inside database/migrations
|--------------------------------------------------------------------------
*/

test('archive path is rejected when config points inside database/migrations', function () {
    config()->set('migrationsquash.archiving.archive_directory', 'database/migrations/archive');

    $archiver = new MigrationArchiver;
    $archivePath = $archiver->getArchivePath();

    expect($archivePath)->not->toStartWith(database_path('migrations').'/');
});

test('archive path honours a config directory outside the migration folder', function () {
    config()->set('migrationsquash.archiving.archive_directory', 'storage/squash-archive');

    $archiver = new MigrationArchiver;

    expect($archiver->getArchivePath())->toStartWith(base_path('storage/squash-archive'));
});

/*
|--------------------------------------------------------------------------
| Fix #6 — enum columns must never render as enum('col', [])
|--------------------------------------------------------------------------
*/

function enumTable(array $allowedValues): Table
{
    $table = new Table('articles');
    $table->addColumn(new Column(
        name: 'status',
        type: 'enum',
        nullable: false,
        default: 'draft',
        allowedValues: $allowedValues,
    ));

    return $table;
}

test('generator emits enum allowed values when they are known', function () {
    $code = (new SquashedMigrationGenerator)->generate(
        enumTable(['draft', 'published', 'archived']),
        'x.php',
    );

    expect($code)->toContain("\$table->enum('status', ['draft', 'published', 'archived'])")
        ->and($code)->not->toContain("enum('status', [])");
});

test('generator falls back to string when enum values are unknown', function () {
    $code = (new SquashedMigrationGenerator)->generate(enumTable([]), 'x.php');

    expect($code)->toContain("\$table->string('status')")
        ->and($code)->not->toContain('enum(');
});

test('generated enum migration is valid PHP', function () {
    $code = (new SquashedMigrationGenerator)->generate(
        enumTable(['draft', 'published']),
        'x.php',
    );

    $tempFile = sys_get_temp_dir().'/enum-gen-'.uniqid().'.php';
    file_put_contents($tempFile, $code);

    exec("php -l {$tempFile} 2>&1", $output, $exitCode);
    unlink($tempFile);

    expect($exitCode)->toBe(0, implode("\n", $output));
});

test('comparator detects an enum allowed-values mismatch', function () {
    $makeSnapshot = fn (array $values) => ['tables' => ['articles' => [
        'name' => 'articles',
        'columns' => [[
            'name' => 'status',
            'type' => 'enum',
            'nullable' => false,
            'default' => 'draft',
            'length' => null,
            'unsigned' => false,
            'collation' => null,
            'auto_increment' => false,
            'allowed_values' => $values,
        ]],
        'indexes' => [],
        'foreign_keys' => [],
    ]]];

    $diff = (new SchemaComparator)->compare(
        $makeSnapshot(['draft', 'published']),
        $makeSnapshot(['draft']),
    );

    expect($diff->isEmpty())->toBeFalse()
        ->and($diff->formatMessage())->toContain('enum values mismatch');
});
