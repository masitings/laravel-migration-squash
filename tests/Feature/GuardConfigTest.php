<?php

/**
 * FR-4.6 — the guards must actually read `config('migrationsquash.guards.*')`.
 * Before this the flags existed in the published config and did nothing.
 */

use MigrationSquash\Guards\DataSeedDetector;
use MigrationSquash\Guards\RawSqlDetector;

function rawSqlFixture(): string
{
    $path = sys_get_temp_dir().'/guard-config-'.uniqid().'.php';

    file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE posts ADD FULLTEXT(title)');
    }
};
PHP);

    return $path;
}

function dataSeedFixture(): string
{
    $path = sys_get_temp_dir().'/guard-config-seed-'.uniqid().'.php';

    file_put_contents($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insert(['name' => 'admin']);
    }
};
PHP);

    return $path;
}

test('raw SQL guard is on by default', function () {
    $file = rawSqlFixture();

    $detector = new RawSqlDetector;

    expect($detector->isEnabled())->toBeTrue()
        ->and($detector->detect([$file]))->toHaveCount(1);

    unlink($file);
});

test('guards.block_raw_sql = false switches the raw SQL guard off', function () {
    config()->set('migrationsquash.guards.block_raw_sql', false);

    $file = rawSqlFixture();
    $detector = new RawSqlDetector;

    expect($detector->isEnabled())->toBeFalse()
        ->and($detector->detect([$file]))->toBeEmpty();

    unlink($file);
});

test('detectAll ignores the config flag so --check still reports the truth', function () {
    config()->set('migrationsquash.guards.block_raw_sql', false);

    $file = rawSqlFixture();

    expect((new RawSqlDetector)->detectAll([$file]))->toHaveCount(1);

    unlink($file);
});

test('guards.block_data_seeding = false switches the data seeding guard off', function () {
    $file = dataSeedFixture();

    expect((new DataSeedDetector)->detect([$file]))->toHaveCount(1);

    config()->set('migrationsquash.guards.block_data_seeding', false);

    expect((new DataSeedDetector)->detect([$file]))->toBeEmpty()
        ->and((new DataSeedDetector)->detectAll([$file]))->toHaveCount(1);

    unlink($file);
});

test('data seeding guard reports the real table name', function () {
    $file = dataSeedFixture();

    $issues = (new DataSeedDetector)->detect([$file]);

    expect(reset($issues))->toContain('roles')
        ->and(reset($issues))->not->toContain('unknown');

    unlink($file);
});
