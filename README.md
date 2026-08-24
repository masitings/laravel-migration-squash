# Laravel Migration Squash

[![Tests](https://github.com/masitings/laravel-migration-squash/actions/workflows/tests.yml/badge.svg)](https://github.com/masitings/laravel-migration-squash/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/masitings/laravel-migration-squash.svg)](https://packagist.org/packages/masitings/laravel-migration-squash)
[![PHP Version](https://img.shields.io/packagist/php-v/masitings/laravel-migration-squash.svg)](https://packagist.org/packages/masitings/laravel-migration-squash)
[![License](https://img.shields.io/packagist/l/masitings/laravel-migration-squash.svg)](LICENSE)

Combine multiple Laravel migration files into a single consolidated migration per table, with automated schema verification to ensure nothing is lost.

> ⚠️ **v1.0.0 and v1.0.1 are non-functional and should not be used.** Both fatal-error before touching the database. Use v1.1.0 or later.

## How It Works

1. **Scan** — discovers all migration files in `database/migrations/`
2. **Guard** — detects migrations with raw SQL or data seeding and excludes them
3. **Sandbox** — runs your migrations in an isolated sandbox on the same engine your app uses (never touches your real database)
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

### Undo a Squash

```bash
php artisan migrate:squash:restore
```

Puts the archived migrations back using the manifest written during the
squash, and removes the generated files. Every archived file is checked
against its recorded hash first: if one has been altered, nothing is restored.

```bash
php artisan migrate:squash:restore --list          # show available archives
php artisan migrate:squash:restore --archive=PATH  # restore a specific one
php artisan migrate:squash:restore --keep-squashed # restore without removing generated files
```

### Options

`migrate:squash`

| Option | Description |
|--------|-------------|
| `--dry-run` | Generate and verify without archiving |
| `--check` | Only check for guarded migrations |
| `--driver=sqlite` | Force sandbox driver (`sqlite` or `mysql`) |
| `--table=users` | Squash only specific tables (repeatable) |

`--table` matches on the migration filename, not on the tables a migration
actually touches. If the resulting set holds a foreign key onto a table left
outside it, the squash is refused rather than generating a migration that
references something the set never creates.

`migrate:squash:restore`

| Option | Description |
|--------|-------------|
| `--list` | List available archives and exit |
| `--archive=PATH` | Restore a specific archive instead of the newest |
| `--force` | Overwrite files already present in the migration folder |
| `--keep-squashed` | Keep the generated migrations instead of removing them |

## Safety Features

### Sandbox Isolation

All migration execution happens in an isolated sandbox connection (`squash-sandbox-*`). A runtime guard verifies the connection name prefix before any write operation. Your real database is never touched.

### The Sandbox Matches Your Database Engine

The sandbox runs the same engine your application runs on. This is not a
performance choice, it is a correctness one.

SQLite has no unsigned bigint. Squash a MySQL app through a SQLite sandbox and
`$table->id()` comes back as `$table->increments('id')`, turning every primary
key from `bigint unsigned` into `int`, and every foreign key onto it fails with
`errno 150`. Verification would still report success, because it compared
SQLite against SQLite.

So the driver is chosen from `database.default`:

| Your app runs on | Sandbox |
|------------------|---------|
| `mysql` / `mariadb` | MySQL (temporary `laravel_squash_*` database) |
| `sqlite` | SQLite in-memory |
| anything else | refused, rather than verified against the wrong engine |

`--driver` overrides this. If the override does not match your application
driver, the command says so and explains what the verification will and will
not prove.

### Schema Verification

After generating squashed migrations, the package runs them in a fresh sandbox and compares the resulting schema against the original. If any differences are found, the squash is aborted.

### Guard System

Migrations containing `DB::statement()`, `DB::unprepared()`, `DB::table()->insert()`, `->update()`, or `->delete()` are automatically detected and excluded from the squash. They are reported but do not cause the entire process to fail.

`DB::raw()` and `DB::select()` are deliberately **not** flagged: `DB::raw()` is
routine inside legitimate schema migrations, and excluding those migrations
would quietly shrink the schema being verified.

Each guard can be switched off, or downgraded to a warning, in the config:

| Key | Effect |
|-----|--------|
| `guards.block_raw_sql` | `false` stops raw SQL from excluding a migration |
| `guards.block_data_seeding` | `false` stops data seeding from excluding a migration |
| `guards.warn_on_detection` | `true` reports findings but squashes them anyway |

`--check` always reports what it finds, whatever these flags say.

### What Verification Compares

Per column: name, type, nullability, default, length, unsigned, collation,
comment and enum allowed values. Per table: indexes (matched by type and
column set, not by generated name) and foreign keys (matched by column and
target, with `onUpdate` / `onDelete` compared).

Every one of these has a test that builds two schemas differing in exactly
that attribute and asserts the difference is reported, so "verification
passed" means something.

Not compared: column order (columns are matched by name), check constraints
other than the one Laravel uses for `enum`, generated and virtual columns,
table engine and charset, and partial indexes.

One limitation worth knowing: **on SQLite, varchar length is not verifiable.**
Laravel's SQLite grammar writes `"name" varchar not null` with no length for
any string size, so nothing downstream can recover it. This does not affect a
MySQL application, which is introspected on MySQL where length is compared.

### Archive Manifest

Before archiving, an `archive-manifest.json` is written containing the original file list, hashes, and timestamps. `migrate:squash:restore` reads it to undo a squash, and refuses to restore a file whose hash no longer matches.

## Foreign Key Handling

Foreign keys are generated in a separate migration file (`*_add_foreign_keys.php`) that runs after all table creation migrations. This eliminates circular dependency issues without needing topological sort.

## Configuration

```php
// config/migrationsquash.php

return [
    'sandbox' => [
        // null follows your application's driver, which is what keeps
        // verification meaningful. Only override deliberately.
        'driver' => env('MIGRATION_SQUASH_DRIVER'),
    ],

    'guards' => [
        'block_raw_sql' => true,
        'block_data_seeding' => true,
        'warn_on_detection' => false,
    ],

    'archiving' => [
        // null archives next to your migration folder. A path inside
        // database/migrations is ignored, since Laravel would pick the
        // archived files back up as live migrations.
        'archive_directory' => null,
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

128 tests, 325 assertions, 87.2% line coverage.

The MySQL tests skip themselves unless a MySQL server is reachable, so the
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
