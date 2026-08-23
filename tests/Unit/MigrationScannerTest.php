<?php

use MigrationSquash\Discovery\MigrationScanner;

test('MigrationScanner regex matches Laravel migration filenames with underscores', function () {
    $scanner = new MigrationScanner(__DIR__.'/../MigrationSquashFixtures/simple');

    // Create a temp fixture
    $fixturePath = __DIR__.'/../MigrationSquashFixtures/simple';

    if (! is_dir($fixturePath)) {
        mkdir($fixturePath, 0755, true);
    }

    $testFile = $fixturePath.'/2024_01_15_123456_create_users_table.php';
    file_put_contents($testFile, "<?php\n// test migration\n");

    try {
        $migrations = $scanner->scan();

        expect($migrations)->not->toBeEmpty();

        $first = $migrations[0];

        expect($first['timestamp'])->toBe('2024_01_15_123456')
            ->and($first['name'])->toBe('2024_01_15_123456_create_users_table');
    } finally {
        @unlink($testFile);
    }
});

test('MigrationScanner accepts explicit nullable parameter', function () {
    // M-13: This should not trigger a deprecation warning in PHP 8.4
    $scanner = new MigrationScanner(null);

    // Just verify construction works — it uses database_path() which may
    // not be available outside Laravel, so we don't call scan()
    expect($scanner)->toBeInstanceOf(MigrationScanner::class);
});

test('MigrationScanner sorts by timestamp', function () {
    $fixturePath = __DIR__.'/../MigrationSquashFixtures/sorting';

    if (! is_dir($fixturePath)) {
        mkdir($fixturePath, 0755, true);
    }

    $files = [
        '2024_03_01_000000_create_comments_table.php',
        '2024_01_01_000000_create_users_table.php',
        '2024_02_01_000000_create_posts_table.php',
    ];

    foreach ($files as $file) {
        file_put_contents($fixturePath.'/'.$file, "<?php\n// test\n");
    }

    try {
        $scanner = new MigrationScanner($fixturePath);
        $migrations = $scanner->scan();

        expect($migrations)->toHaveCount(3)
            ->and($migrations[0]['name'])->toContain('users')
            ->and($migrations[1]['name'])->toContain('posts')
            ->and($migrations[2]['name'])->toContain('comments');
    } finally {
        foreach ($files as $file) {
            @unlink($fixturePath.'/'.$file);
        }
        @rmdir($fixturePath);
    }
});
