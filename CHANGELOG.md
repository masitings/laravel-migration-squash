# Changelog

All notable changes to `laravel-migration-squash` will be documented in this file.

## [Unreleased]

Second remediation pass. Six defects found in review of the v1.1.0 work,
all covered by regression tests.

### Fixed

- **Squashed migrations were written to `database/migrations/` before the
  confirmation prompt.** Answering "no" left both the new and the original
  files in place, producing a duplicate `Schema::create` for every table on
  the next `migrate:fresh`. Nothing is written or moved until the user
  confirms; the originals are archived first so a failed write is recoverable
  from the manifest, and the archive path is printed if that happens.
- **Guarded migrations ran before the squashed ones.** Guarded files keep
  their original timestamps while generated files used `time()`, so Laravel's
  filename ordering put the squashed files last. A guarded
  `Schema::table('users', ...)` then ran before `users` existed. Generated
  filenames are now laid out in the seconds immediately *before* the earliest
  original migration.
- **Guards blocked `DB::raw()` and `DB::select()`.** `DB::raw()` is routine in
  legitimate schema migrations (`->default(DB::raw('CURRENT_TIMESTAMP'))`) and
  `DB::select()` is a read. Both excluded the migration from the sandbox run,
  which silently shrank the schema being verified while verification still
  passed. Only `DB::statement()` and `DB::unprepared()` are blocked now.
- **The MySQL sandbox database was never created.** `--driver=mysql` failed
  with "Unknown database". The factory now creates the database through a
  bootstrap connection and drops it in `destroy()`. The drop is guarded by a
  `laravel_squash_` name prefix so a real database can never be targeted.
- **The archive folder resolved back inside `database/migrations/`.** The
  shipped config still pointed there, so archived files stayed on the
  migration path. The config default is now `null`, the archive lands next to
  the migration folder wherever that folder is, and any configured path
  inside `database/migrations` is ignored in favour of the default.
- **`enum` columns generated `$table->enum('col', [])`**, which renders as
  invalid `ENUM()` SQL. Allowed values are now parsed from the MySQL raw type
  and from SQLite's `CHECK (... IN (...))` constraint, carried through the
  snapshot, and compared during verification. When values genuinely cannot be
  recovered the column falls back to `string()` rather than emitting invalid
  SQL.

Two further defects surfaced once the MySQL path was actually exercised by
tests rather than only read:

- **`normalizeType()` did not collapse `enum(...)` / `set(...)`.** Its length
  regex only matches digits inside the parentheses, so a MySQL column reported
  as `enum('draft','published')` kept the entire raw string as its "type". The
  generator then fell through to its `default` branch and emitted
  `$table->string()`, silently turning every MySQL enum into a varchar(255).
- **MySQL booleans round-tripped as `tinyint(4)`.** MySQL renders `boolean` as
  `tinyint(1)`; the SQLite introspector already collapsed that back to
  `boolean` but the MySQL one did not, so the generator emitted
  `tinyInteger()` and verification failed on a length mismatch. Boolean
  defaults are now normalized to real booleans as well.

### Added

- `tests/Unit/RemediationRegressionTest.php` and
  `tests/Feature/SquashCommandSafetyTest.php` — 15 regression tests covering
  all six defects above.
- `tests/Feature/MySqlSandboxTest.php` — 6 tests covering the MySQL sandbox:
  database creation, teardown, the prefix guard refusing a non-sandbox
  database, enum parsing from the raw type, and a full
  introspect → generate → verify round trip. They skip themselves when no
  MySQL server is reachable.
- `.github/workflows/tests.yml` — matrix across PHP 8.1 to 8.4 and Laravel 10
  to 12 with a MySQL 8.0 service, a coverage job that fails below 70%, a Pint
  code-style job, and a `php -l` sweep.
- `laravel/pint` as a dev dependency, plus `composer test`,
  `composer test:coverage`, `composer lint` and `composer lint:fix` scripts.
- `SandboxConnectionFactory::SANDBOX_DB_PREFIX` as the guard for MySQL
  sandbox teardown.
- `Column::$allowedValues` and the `column_enum_values_mismatch` diff type.
- `tests/Feature/TableFilterTest.php`, `tests/Feature/SchemaShapesTest.php` and
  `tests/Unit/ValueObjectsTest.php` — 16 further tests covering the `--table`
  refusal, and round trips for tables with no `id`, composite primary keys,
  composite unique indexes, self-referencing foreign keys, circular foreign key
  pairs and column defaults.

The three remaining PRD items are now closed:

- **`migrate:squash:restore` (FR-5.4).** Undoes a squash from the manifest the
  archive step already wrote. Verifies every archived file against its recorded
  md5 before touching anything and refuses the whole restore if one has been
  altered, if a manifest entry is missing from the archive, or if a destination
  already exists (`--force` overrides the last one). Also removes the generated
  migrations unless `--keep-squashed` is passed. `--list` shows the available
  archives, `--archive=PATH` picks one.
- **`toMatchSchema()` (FR-6.4).** A Pest expectation that compares two schemas
  and, on failure, reports the full `SchemaDiff` message instead of "false is
  not true". Accepts a snapshot array or a live connection name on either side.
  A companion `toDifferFromSchema()` asserts the diff types that must be
  present.
- **Guards read their config (FR-4.6).** `guards.block_raw_sql` and
  `guards.block_data_seeding` now actually switch their guard off, and
  `guards.warn_on_detection` reports findings without excluding the migration.
  `--check` calls a new `detectAll()` so the diagnostic still reports the truth
  when a guard is switched off.

A fourth pass then closed the last known correctness gap and the dead code
left behind by earlier passes:

- **`--table` could produce a squash that only worked by accident.** Squashing
  a subset whose foreign keys point at tables outside it generated an FK
  migration referencing a table the squashed set never creates, exited 0, and
  archived the originals. Whether the result migrated depended entirely on how
  the leftover migrations happened to sort. The command now refuses a set that
  is not self-contained, names the offending foreign keys, and writes nothing.
  The same check catches a guard excluding the migration that creates a
  referenced table.
- **The base timestamp was computed after filtering.** `--table` and the guards
  leave migrations behind with their original timestamps, so the base has to
  come from the full scan. It is now resolved before any filtering.
- **`needsMySQL()` had become dead code** when auto-detection was dropped from
  the sandbox setup. It now warns when migrations use MySQL-specific column
  types and suggests `--driver=mysql`, rather than silently switching drivers
  on someone who has no MySQL server configured.

### Changed

- `archiving.archive_directory` now defaults to `null`, meaning "next to your
  migration folder". The previous default resolved through `base_path()` while
  the fallback used `database_path()`, so the archive landed in the wrong place
  on an app with a customised database path.

## [v1.1.0] - 2026-08-24

### ⚠️ Important Notice


**v1.0.0 is non-functional and should not be used.** The command would fatal error before touching the database. v1.1.0 is the first version that actually works.

### Fixed

- **[CRITICAL]** `handle()` used undefined variables `$migrationFiles` and `$connectionName` — command crashed at step 4 (C-1)
- **[CRITICAL]** `Table::$columns`, `$indexes`, `$foreignKeys` were `readonly` but mutated via `addColumn()` — caused `Error: Cannot indirectly modify readonly property` (C-2)
- **[CRITICAL]** Generator used heredoc with unescaped `$table` variable — caused `Object of class Table could not be converted to string` (C-3)
- **[CRITICAL]** Sandbox connection was never registered in Laravel's config — `Artisan::call('migrate')` and `fresh()` hit the **user's real database** (C-4)
- **[CRITICAL]** `SandboxConnectionFactory::create()` was an instance method called statically (C-5)
- **[CRITICAL]** `SchemaDiff::add()` used `compact(...$details)` spread — caused `ArgumentCountError` when verification found differences (C-6)
- `Column::$autoIncrement` was `?string` but received `bool` — TypeError (H-9)
- Guard system `scanMigration()` returned empty local variables instead of visitor results (H-1)
- `SqlDetectorVisitor` used `$node->var` instead of `$node->class` for `StaticCall` — `DB::statement()` never detected (H-2)
- `SqlDetectorVisitor` referenced `$this->printer` which didn't exist in the visitor class (H-3)
- `MigrationScanner::extractTableInfo()` never added visitor to traverser, accessed wrong object (H-4)
- SQLite introspection always returned empty foreign keys (H-5)
- SQLite column introspection returned raw PRAGMA output instead of normalized format (H-6)
- SQLite index introspection returned raw PRAGMA output without column info (H-7)
- `SchemaDiff::formatMessage()` accessed non-existent keys in diff entries (H-8)
- `$this->success()` does not exist in `Illuminate\Console\Command` (M-1)
- `--driver` signature set default to literal string `"mysql|sqlite"` (M-2)
- `--table` not declared as array (`--table=*`) (M-3)
- `getConnectionFromConfig()` hardcoded return `'sqlite'` (M-4)
- `Column::fromDb()` operator precedence bug in virtual detection (M-5)
- Column nullable logic defaulted to `true` when key missing (M-6)
- `TableGrouping::resolveOrder()` always built empty graph (M-7)
- `TableGrouping` was never called — dead code (M-8)
- Migration filename regex `/(\d{14})/` never matched Laravel format with underscores (M-9)
- `ServiceProvider` missing `mergeConfigFrom()` — config always null (M-10)
- Archive path was inside `database/migrations/`, would be scanned by Laravel (M-11)
- Generator produced backslash-escaped quotes and non-interpolated collation (M-12)
- Implicit nullable `string $param = null` deprecated in PHP 8.4 (M-13)

### Added

- **Sandbox isolation guard**: runtime check that connection name starts with `squash-sandbox-`
- **Deferred FK migration**: foreign keys generated in separate file to avoid circular dependencies
- **Archive manifest**: `archive-manifest.json` written before archiving for rollback safety
- **SQLite FK introspection**: via `PRAGMA foreign_key_list(table)`
- **SQLite index column reading**: via `PRAGMA index_info(index_name)`
- **Type normalization**: canonical type mapping for cross-driver comparison
- **Signature-based comparison**: indexes and FKs compared by content, not by generated name
- **MySQL parameter binding**: `INFORMATION_SCHEMA` queries use `?` placeholders
- **Test suite**: Pest tests with Orchestra Testbench (Unit + Feature)
- **Sandbox isolation test**: verifies default connection is never touched

### Removed

- `Discovery/TableGrouping.php` — dead code, graph was always empty
- `MigrationScanner::extractTableInfo()` — broken visitor logic, never worked

### Changed

- `SandboxConnectionFactory::create()` is now `static` and returns connection name string
- `SandboxRunner` requires explicit connection name (no default)
- `SchemaIntrospector` requires explicit connection name (no default)
- Generator uses stub files with `str_replace` instead of heredoc
- Archive default path moved to `database/migrations-archive/` (outside migrations/)
- Guarded migrations are excluded from squash (not fatal), reported at end

## [v1.0.0] - 2026-08-23

> ⚠️ **This version is non-functional. Do not use.** See v1.1.0.

Initial release. Published to Packagist but contained critical bugs preventing any functionality.
