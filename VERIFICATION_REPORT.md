# Verification Report

**Package:** `masitings/laravel-migration-squash`
**Report date:** 2026-08-23
**Status:** verified locally; the CI matrix has not run yet

## Summary

v1.0.0 was published but could not execute at all. Three review passes have
since closed 6 critical, 9 high and 13 medium severity defects, plus 8 more
found afterwards, and the package is now covered by an automated suite.

Everything below is a measurement, not a claim. Re-run the commands to
reproduce it.

## Measured results

```bash
DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD= vendor/bin/pest --coverage
```

| Metric | Value |
|--------|-------|
| Tests | 124 passed |
| Assertions | 306 |
| Line coverage | 85.8% |
| Duration | ~3s |

Verified on PHP 8.4.21, Laravel 12.67.0, orchestra/testbench 10.11.0,
Pest 3.8.7, against MariaDB 10.11.14.

Per-file coverage below 80%:

| File | Coverage |
|------|----------|
| `Console/Commands/MigrateSquashCommand` | 79.5% |
| `Console/Commands/MigrateSquashRestoreCommand` | 73.8% |
| `Verification/SchemaComparator` | 71.8% |
| `Verification/SchemaDiff` | 71.4% |
| `Sandbox/SandboxRunner` | 70.2% |

The uncovered lines are mostly error-reporting branches that need a failing
database to reach.

## What the suite actually proves

| Area | Evidence |
|------|----------|
| Sandbox isolation | The default connection is asserted untouched after a squash; `SandboxRunner` rejects any connection name without the `squash-sandbox-` prefix |
| Nothing written before consent | Declining the prompt leaves the migration folder byte-identical |
| Ordering | Generated files are asserted to sort before every migration left behind, whether excluded by a guard or by `--table` |
| Self-containment | A squash whose foreign keys point outside the set is refused, with nothing written or archived |
| Schema round trip | Tables with no `id`, composite primary keys, composite unique indexes, self-referencing foreign keys, circular foreign key pairs, enums and column defaults all round trip to an empty diff |
| MySQL | Sandbox database creation and teardown, the prefix guard refusing a non-sandbox database, enum parsing from the raw type, and a full round trip |
| Generated code | Every generated migration is checked with `php -l` |
| Engine match | The sandbox driver is asserted to follow `database.default`; an unsupported engine resolves to "refuse", not "fall back to SQLite" |
| Verification actually detects change | 17 audit tests build two schemas differing in exactly one attribute (nullability, default, length, type, missing column, missing index, unique downgraded to index, missing foreign key, changed onDelete, missing table, enum values, SQLite collation, MySQL collation, unsigned, comment) and assert each difference is reported |
| Restore | Hash mismatch, missing manifest entry and existing destination each abort the whole restore |

## Reproducing the safety claims

```bash
# no DB:: call in the sandbox or introspection layer without an explicit connection
grep -rn 'DB::' src/MigrationSquash/Sandbox/ src/MigrationSquash/Introspection/

# only $name is readonly on Table
grep -n 'readonly' src/MigrationSquash/Schema/Table.php

# no $this->success(), which does not exist on Illuminate\Console\Command
grep -rn 'success()' src/MigrationSquash/

# every file parses
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Not yet verified

- **The CI matrix.** Only PHP 8.4 + Laravel 12 has actually been run. The
  Laravel 10 and 11 rows are derived from version constraints and will be
  proven or disproven by the first CI run.
- **Pint.** The code follows the Laravel preset by eye but `vendor/bin/pint`
  has not been run against it.
- **A real Laravel application.** Everything here runs through Orchestra
  Testbench against synthetic migration folders. That is not the same as one
  application with a long, messy migration history. Run `migrate:squash
  --dry-run` there before trusting it; that mode writes nothing.

## Known limitations

- PostgreSQL and SQL Server are not supported (planned for v1.2.0).
- Column order can differ between the original and generated schema. The
  comparator matches columns by name, so this does not fail verification.
- `--table` matches on the migration filename, not on the tables a migration
  actually touches. A filtered set that is not self-contained is refused
  rather than silently mis-squashed.
- On SQLite, `enum` is stored as a `CHECK (... IN (...))` constraint. Values
  are parsed back out, but other check constraints are not compared.
- On SQLite, varchar length is not verifiable at all: Laravel's SQLite grammar
  writes `varchar` with no length for any string size. MySQL applications are
  unaffected, since they are introspected on MySQL.
- Not compared anywhere: column order (columns are matched by name), generated
  and virtual columns, table engine and charset, partial indexes.
