# Laravel Migration Squash

[![Tests](https://github.com/masitings/laravel-migration-squash/actions/workflows/tests.yml/badge.svg)](https://github.com/masitings/laravel-migration-squash/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/masitings/laravel-migration-squash.svg)](https://packagist.org/packages/masitings/laravel-migration-squash)
[![PHP Version](https://img.shields.io/packagist/php-v/masitings/laravel-migration-squash.svg)](https://packagist.org/packages/masitings/laravel-migration-squash)
[![License](https://img.shields.io/packagist/l/masitings/laravel-migration-squash.svg)](LICENSE)

Combine multiple Laravel migration files into a single consolidated migration per table, with automated schema verification to ensure nothing is lost.

> ⚠️ **v1.0.0 is non-functional and should not be used.** Please use v1.1.0 or later.

## How It Works

1. **Scan** — discovers all migration files in `database/migrations/`
2. **Guard** — detects migrations with raw SQL or data seeding and excludes them
3. **Sandbox** — runs your migrations in an isolated SQLite in-memory database (never touches your real database)
4. **Introspect** — reads the resulting schema (tables, columns, indexes, foreign keys)
5. **Generate** — creates one clean migration per table, plus a separate FK migration
6. **Verify** — runs the generated migrations and compares the schema to ensure they match
7. **Archive** — moves original migrations to an archive directory with a manifest for rollback

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13

## Installation

```bash
composer require masitings/laravel-migration-squash
```

The service provider is auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=migrationsquash-config
```

## Usage

### Dry Run (recommended first)

```bash
php artisan migrate:squash --dry-run
```

Generates and verifies squashed migrations without modifying any files.

### Full Squash

```bash
php artisan migrate:squash
```

Generates squashed migrations, verifies them, and archives the originals.

### Check Mode

```bash
php artisan migrate:squash --check
```

Only checks for migrations that contain raw SQL or data seeding — does not squash.

### Options

| Option | Description |
|--------|-------------|
| `--dry-run` | Generate and verify without archiving |
| `--check` | Only check for guarded migrations |
| `--driver=sqlite` | Force sandbox driver (`sqlite` or `mysql`) |
| `--table=users` | Squash only specific tables (repeatable) |

## Safety Features

### Sandbox Isolation

All migration execution happens in an isolated sandbox connection (`squash-sandbox-*`). A runtime guard verifies the connection name prefix before any write operation. Your real database is never touched.

### Schema Verification

After generating squashed migrations, the package runs them in a fresh sandbox and compares the resulting schema against the original. If any differences are found, the squash is aborted.

### Guard System

Migrations containing `DB::statement()`, `DB::unprepared()`, `DB::table()->insert()`, `->update()`, or `->delete()` are automatically detected and excluded from the squash. They are reported but do not cause the entire process to fail.

### Archive Manifest

Before archiving, an `archive-manifest.json` is written containing the original file list, hashes, and timestamps for rollback safety.

## Foreign Key Handling

Foreign keys are generated in a separate migration file (`*_add_foreign_keys.php`) that runs after all table creation migrations. This eliminates circular dependency issues without needing topological sort.

## Configuration

```php
// config/migrationsquash.php

return [
    'sandbox' => [
        'driver' => env('MIGRATION_SQUASH_DRIVER', 'sqlite'),
    ],

    'guards' => [
        'block_raw_sql' => true,
        'block_data_seeding' => true,
    ],

    'archiving' => [
        'archive_directory' => 'database/migrations-archive',
    ],

    'verification' => [
        'strict_mode' => true,
        'ignore_differences' => [],
    ],
];
```

## Supported Databases

| Driver | Status |
|--------|--------|
| SQLite | ✅ Full support, covered by the test suite (default sandbox) |
| MySQL | ✅ Supported, covered end-to-end by the test suite |
| PostgreSQL | 🔜 Planned for v1.2.0 |
| SQL Server | 🔜 Planned for v1.2.0 |

The MySQL sandbox creates and drops a temporary `laravel_squash_*` database,
so the configured MySQL user needs `CREATE DATABASE` privileges. Teardown will
only ever drop a database whose name carries that prefix.

## Testing

```bash
composer install
composer test
```

58 tests, 154 assertions, 79.1% line coverage.

The six MySQL tests skip themselves unless a MySQL server is reachable, so the
suite is green on a machine that only has SQLite. Point them at a server to run
them:

```bash
DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= composer test
```

Coverage needs pcov or xdebug installed:

```bash
composer test:coverage   # fails below 70%
```

Code style is enforced with Pint:

```bash
composer lint       # check
composer lint:fix   # fix
```

CI runs the suite across PHP 8.1 to 8.4 and Laravel 10 to 12, with a MySQL 8.0
service so the MySQL path is exercised on every push.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

MIT License. See [LICENSE](LICENSE) for details.

## Author

[Rafi Bagaskara Halilintar](https://masiting.dev) — [hallo@masiting.dev](mailto:hallo@masiting.dev)
