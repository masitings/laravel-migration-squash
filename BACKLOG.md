# Backlog

Open items for `masitings/laravel-migration-squash`, as of 2026-08-23.

Nothing here is speculative. Every item is either a gate that has genuinely
not been met, a limitation proven by a test, or a decision that was
deliberately deferred.

---

## Blocking the v1.1.0 release

These are the reasons v1.1.0 is not tagged yet.

### 1. CI has never run

`GET /repos/masitings/laravel-migration-squash/actions/runs` returns
`{"total_count": 0}`. There is no `.yml` anywhere in the repo, so the workflow
was never committed. The README already carries a badge pointing at it, which
will render broken until this lands.

- [ ] Save the generated `tests.yml` to `.github/workflows/tests.yml`
- [ ] Commit and push it
- [ ] Confirm the run goes green

**Why it matters:** the Laravel 10 and Laravel 11 matrix rows have never been
executed even once. Two things that had never been run turned out to be broken
the moment they were: the MySQL sandbox path, and the SQLite collation parser.
This is the third such thing.

### 2. Never run against a real Laravel application

Largely closed. `tests/Feature/RealisticAppTest.php` now builds a
15-migration history shaped like a real app, squashes it for real, replays
**only the squashed output** against an empty database and compares the
resulting schema. It passes on SQLite and MySQL, and a companion test restores
the archive byte for byte.

What remains is confirmation on an actual repository, which no fixture can
substitute for:

- [ ] Run `php artisan migrate:squash --dry-run` on your own app
- [ ] Confirm the schema diff comes back empty

`--dry-run` writes nothing, so this is safe to do at any time.

### 3. Tag `v1.0.1` points at broken code

```
refs/tags/v1.0.1^{}  →  6a4b98f  "feat: Add comprehensive test coverage for v1.0.0"
```

`6a4b98f` predates every fix. It is the version that fatal-errors in `handle()`,
violates readonly in `Table::addColumn()`, generates invalid PHP, and whose
sandbox writes to the user's real database. Anyone installing from Packagist
today may receive it.

- [ ] Check whether v1.0.1 is live on Packagist
- [ ] Mark v1.0.0 and v1.0.1 as abandoned, or state it plainly in the release notes
- [ ] Release the fixed work as v1.1.0, skipping the v1.0.x line entirely

### 4. `main` is merged locally but not pushed

Local `main` is at `19bce9c`; `origin/main` is still at `6a4b98f`.

- [ ] `git push origin main`

---

## Known limitations

Documented deliberately rather than papered over. Each has a test that pins
the current behaviour.

| Limitation | Notes |
|------------|-------|
| SQLite discards varchar length | Laravel's SQLite grammar writes `varchar` with no length for any string size, so `string('n', 100)` and `string('n', 200)` are the same schema. Does not affect MySQL apps, which are introspected on MySQL. Pinned by a test. |
| Column order is not compared | Columns are matched by name. Reordering a table passes verification. |
| Check constraints are not compared | Except the `CHECK (... IN (...))` form Laravel uses for `enum`, whose values are parsed and compared. |
| Generated and virtual columns | Not captured or compared. |
| Table engine and charset | Not captured or compared (MySQL). |
| Partial indexes | Not captured or compared. |
| `--table` matches filenames | It filters on the migration filename, not on the tables a migration actually touches. A filtered set that is not self-contained is refused rather than mis-squashed, so this is safe, just blunt. |

---

## Deferred to v1.2.0

- PostgreSQL support. Currently an app on `pgsql` is **refused**, which is the
  correct behaviour: there is no sound sandbox for it, and verifying against
  SQLite would repeat exactly the defect that made the MySQL path unsafe.
- SQL Server support.
- Partial squash by date range (`--before=2024_01_01`).
- Integration with `migrate:status`, to detect migrations already run in
  production.
- Interactive table picker.

---

## Smaller cleanups

- [ ] Flip the `prefer-lowest` CI row from `continue-on-error: true` to `false`
      once it has proven stable.
- [ ] Raise coverage on `Verification/SchemaDiff` (69.4%) and
      `Sandbox/SandboxRunner` (70.2%). The uncovered lines are mostly
      error-reporting branches that need a failing database to reach.
- [ ] Delete `.git/_trash/`. It holds empty git lock files and hardlinked temp
      objects left behind because the working folder was mounted without
      delete permission. `git fsck --full` is clean; removing it is safe.
- [ ] Decide whether `PRD.md`, `BACKLOG.md` and `VERIFICATION_REPORT.md` should
      be `export-ignore`d in `.gitattributes` so they stay out of the
      distributed package. `PRD.md` already is.

---

## Where things stand

| | |
|---|---|
| Tests | 128 passed, 325 assertions |
| Line coverage | 87.2% |
| Verified on | PHP 8.4.21, Laravel 12.67.0, testbench 10.11.0, Pest 3.8.7, MariaDB 10.11.14 |
| Pint | clean (it only ever reformatted test files; `src/` was already conforming) |
| Not verified | the CI matrix, and any real application |

See `CHANGELOG.md` for what was fixed and `VERIFICATION_REPORT.md` for what the
test suite actually proves.
