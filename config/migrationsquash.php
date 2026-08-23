<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sandbox Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how the sandbox connection behaves during migration squashing.
    | Default is 'sqlite' for speed, but can be set to 'mysql' if needed.
    |
    */

    'sandbox' => [
        'driver' => env('MIGRATION_SQUASH_DRIVER', 'sqlite'),

        // If using MySQL, these will be used to create temporary database
        'mysql_host' => env('DB_HOST', '127.0.0.1'),
        'mysql_port' => env('DB_PORT', '3306'),
        'mysql_database' => 'laravel_squash_temp_',
        'mysql_username' => env('DB_USERNAME', 'root'),
        'mysql_password' => env('DB_PASSWORD', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard Settings
    |--------------------------------------------------------------------------
    |
    | Configure which types of migrations should trigger warnings or failures.
    |
    */

    'guards' => [
        'block_raw_sql' => true,
        'block_data_seeding' => true,
        'warn_on_detection' => false, // Set true to warn instead of block
    ],

    /*
    |--------------------------------------------------------------------------
    | Archiving Configuration
    |--------------------------------------------------------------------------
    |
    | Where archived migrations should be stored and retention settings.
    |
    */

    'archiving' => [
        // Path relative to the project root. Leave null to archive into a
        // "migrations-archive" folder next to your migration folder.
        //
        // Whatever you set must live OUTSIDE database/migrations, otherwise
        // Laravel and this package pick archived files back up as live
        // migrations. A path inside the migration folder is ignored and the
        // default is used instead.
        'archive_directory' => null,
        'retention_days' => 365, // Delete archives older than this

        // Auto-create archive directory on first use
        'auto_create_directory' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Settings
    |--------------------------------------------------------------------------
    |
    | Controls how strict schema verification is.
    |
    */

    'verification' => [
        // Require exact match vs allow minor differences
        'strict_mode' => true,

        // Ignore certain non-critical differences in non-strict mode
        'ignore_differences' => [
            // 'collation', // Ignore collation differences
            // 'order',      // Ignore column order
        ],
    ],

];
