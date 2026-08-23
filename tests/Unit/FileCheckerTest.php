<?php

use MigrationSquash\Guards\FileChecker;

test('FileChecker scanMigration returns visitor results, not empty locals', function () {
    $checker = new FileChecker;

    // Create a temp migration with DB::statement
    $tempFile = sys_get_temp_dir().'/test_migration_'.uniqid().'.php';

    file_put_contents($tempFile, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN age INT');
    }
};
PHP);

    try {
        $result = $checker->scanMigration($tempFile);

        // H-1: Must return actual visitor results, not empty arrays
        expect($result)->toHaveKey('rawSql')
            ->and($result)->toHaveKey('dataSeeding')
            ->and($result['rawSql'])->not->toBeEmpty();
    } finally {
        @unlink($tempFile);
    }
});

test('FileChecker detects DB::table()->insert() as data seeding', function () {
    $checker = new FileChecker;

    $tempFile = sys_get_temp_dir().'/test_migration_seed_'.uniqid().'.php';

    file_put_contents($tempFile, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->insert([
            ['name' => 'admin'],
            ['name' => 'user'],
        ]);
    }
};
PHP);

    try {
        $result = $checker->scanMigration($tempFile);

        // FR-4.4: Should extract actual table name, not 'unknown'
        expect($result['dataSeeding'])->not->toBeEmpty()
            ->and($result['dataSeeding'][0]['table'])->toBe('roles')
            ->and($result['dataSeeding'][0]['type'])->toBe('insert');
    } finally {
        @unlink($tempFile);
    }
});

test('FileChecker returns empty arrays for clean migration', function () {
    $checker = new FileChecker;

    $tempFile = sys_get_temp_dir().'/test_migration_clean_'.uniqid().'.php';

    file_put_contents($tempFile, <<<'PHP'
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
            $table->string('name');
            $table->timestamps();
        });
    }
};
PHP);

    try {
        $result = $checker->scanMigration($tempFile);

        expect($result['rawSql'])->toBeEmpty()
            ->and($result['dataSeeding'])->toBeEmpty();
    } finally {
        @unlink($tempFile);
    }
});
