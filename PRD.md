# PRD: Remediasi Laravel Migration Squash

**Package:** `masitings/laravel-migration-squash`
**Versi sekarang:** v1.0.0 (published di Packagist)
**Versi target:** v1.1.0
**Author:** Rafi Bagaskara Halilintar
**Tanggal:** 23 Agustus 2026
**Status:** Draft

---

## 1. Ringkasan Eksekutif

Package `laravel-migration-squash` v1.0.0 sudah terbit di Packagist, tapi **tidak bisa dijalankan sama sekali**. Command `php artisan migrate:squash` fatal error sebelum menyentuh database, karena ada undefined variable di `handle()`. Di belakang error itu masih ada enam lapisan bug fatal lain: object schema tidak bisa diisi (readonly violation), generator menghasilkan file PHP yang tidak valid, verifikasi schema crash tepat pada saat ia menemukan perbedaan, guard system tidak pernah mendeteksi apa pun, dan sandbox tidak pernah terisolasi dari database asli user.

Audit ini menemukan **6 bug Critical, 9 High, 13 Medium**, dan 5 masalah integritas dokumentasi. Empat di antaranya sudah direproduksi langsung di PHP 8.4.21.

Dokumen ini mendefinisikan scope perbaikan menuju v1.1.0: package yang benar-benar jalan, terverifikasi lewat test suite otomatis, dan tidak berisiko merusak database user.

**Prioritas nomor satu bukan fitur baru, tapi keamanan.** Bug #4 di bawah adalah data-loss risk.

---

## 2. Latar Belakang dan Bukti Masalah

Audit dilakukan tanggal 23 Agustus 2026 terhadap seluruh isi `src/MigrationSquash/`. Dua temuan direproduksi langsung di PHP 8.4.21.

### 2.1 Severity: Critical (blocker)

| ID | Lokasi | Masalah |
|----|--------|---------|
| C-1 | `Console/Commands/MigrateSquashCommand.php` | `handle()` memakai `$migrationFiles` dan `$connectionName` yang tidak pernah didefinisikan. Return value `step1_DetectTable()` dibuang. Command fatal di step 4. |
| C-2 | `Schema/Table.php` | `$columns`, `$indexes`, `$foreignKeys` di-declare `readonly` tapi `addColumn()` melakukan `$this->columns[] = ...`. Reproduksi: `Error: Cannot indirectly modify readonly property Table::$columns` |
| C-3 | `Generation/SquashedMigrationGenerator.php` | Heredoc `<<<PHP` tidak escape `$table`, padahal `$table` di scope itu adalah object `Table`. Reproduksi: `Error: Object of class Table could not be converted to string` |
| C-4 | `Sandbox/SandboxRunner.php` + `SandboxConnectionFactory.php` | Config sandbox dibuat tapi tidak pernah didaftarkan ke `database.connections`. `Artisan::call('migrate')` dan `fresh()` (yang menjalankan `DROP TABLE`) mengenai **koneksi default**, yaitu database asli user. |
| C-5 | `MigrateSquashCommand.php:~150` | `SandboxConnectionFactory::create()` adalah method instance tapi dipanggil secara statis. |
| C-6 | `Verification/SchemaDiff.php` | `add()` melakukan `compact('table', 'type', ...$details)`. Spread associative array jadi named argument ke `compact`. Reproduksi: `ArgumentCountError: compact() does not accept unknown named parameters`. Artinya **verifikasi crash tepat pada saat ia berhasil menemukan perbedaan.** Satu-satunya pemanggilan yang lolos adalah `add($t, 'table_missing', [])` karena details-nya kosong. |

### 2.2 Severity: High

| ID | Lokasi | Masalah |
|----|--------|---------|
| H-1 | `Guards/FileChecker.php` | `scanMigration()` mengembalikan `compact('rawSql', 'dataSeeding')` dari variabel lokal yang masih kosong, bukan dari `$visitor->getResults()`. **Guard system selalu mengembalikan array kosong.** Raw SQL dan data seeding tidak pernah terdeteksi. |
| H-2 | `Guards/FileChecker.php` | `SqlDetectorVisitor` memeriksa `$node->var` untuk `StaticCall`, padahal property yang benar adalah `$node->class`. Deteksi `DB::statement()` tidak akan pernah match walaupun H-1 diperbaiki. |
| H-3 | `Guards/FileChecker.php` | `SqlDetectorVisitor` memanggil `$this->printer` yang tidak ada di class itu (adanya di `FileChecker`). Fatal kalau jalur deteksi sempat tercapai. |
| H-4 | `Discovery/MigrationScanner.php` | `extractTableInfo()`: `$extractor` dibuat tapi tidak pernah di-`addVisitor()` ke traverser. Lalu `$visitor->tableOperations` diakses pada object `NodeTraverser`. Di dalam loop, visitor baru ditambahkan setiap iterasi sehingga menumpuk. Method ini tidak berfungsi. |
| H-5 | `Introspection/SchemaIntrospector.php` | Introspeksi SQLite selalu mengembalikan `$foreignKeys = []`. Verifikasi foreign key tidak pernah benar-benar terjadi di jalur default. |
| H-6 | `Introspection/SchemaIntrospector.php` | `getSQLiteColumns()` mengembalikan hasil `PRAGMA table_info` apa adanya (`cid, name, type, notnull, dflt_value, pk`), sementara `Column::fromDb()` mencari key `default`, `length`, `unsigned`, `collation`. Semua key itu tidak ada, jadi hasil introspeksi tidak akurat. |
| H-7 | `Introspection/SchemaIntrospector.php` | `getSQLiteIndexes()` mengembalikan `PRAGMA index_list` (`seq, name, unique, origin, partial`), sementara `Index::fromDb()` mencari `key_name` dan `columns`. Semua index jadi bernama string kosong tanpa kolom, dan saling bertabrakan waktu di-key di comparator. |
| H-8 | `Verification/SchemaDiff.php` | Lanjutan C-6: `formatMessage()` membaca `$diff['column']`, `$diff['expected']`, `$diff['index']`, `$diff['fk']`. Key-key itu tidak akan ada bahkan setelah C-6 diperbaiki, kecuali struktur `$differences` ikut dirapikan. |
| H-9 | `Schema/Column.php` | Property `$autoIncrement` bertipe `?string`, tapi `fromDb()` dan `MigrateSquashCommand` mengisinya dengan `bool`/`null`. TypeError. |

### 2.3 Severity: Medium

| ID | Lokasi | Masalah |
|----|--------|---------|
| M-1 | `MigrateSquashCommand.php` | `$this->success()` tidak ada di `Illuminate\Console\Command`. |
| M-2 | `MigrateSquashCommand.php` | Signature `{--driver=mysql\|sqlite : ...}` membuat default value menjadi string literal `"mysql\|sqlite"`, bukan pilihan. |
| M-3 | `MigrateSquashCommand.php` | `--table` dibaca sebagai array (`foreach`) tapi tidak di-declare sebagai `--table=*`. |
| M-4 | `MigrateSquashCommand.php` | `getConnectionFromConfig()` hardcode return `'sqlite'`, mengabaikan config sandbox yang baru dibuat. |
| M-5 | `Schema/Column.php` | `fromDb()`: `$columnInfo['extra'] ?? null === 'VIRTUAL' ? true : null` salah presedensi operator. |
| M-6 | `Schema/Column.php` | Logika nullable: `($columnInfo['null'] ?? 'YES') === 'YES'` membuat semua kolom SQLite dianggap nullable karena key `null` tidak pernah ada. |
| M-7 | `Discovery/TableGrouping.php` | `resolveOrder()` selalu membangun graph kosong, jadi topological sort dan deteksi circular FK tidak melakukan apa pun. Klaim "Circular FK Support" di README tidak punya implementasi. |
| M-8 | `Discovery/TableGrouping.php` | Class ini tidak pernah dipanggil oleh command. Dead code. |
| M-9 | `Discovery/MigrationScanner.php` | Regex `/(\d{14})/` tidak pernah match nama file migration Laravel (`2024_01_01_000001_...` mengandung underscore). Field `timestamp` selalu fallback ke `'00000000000000'`. |
| M-10 | `MigrationSquashServiceProvider.php` | Tidak ada `mergeConfigFrom()`. `config('migrationsquash.*')` selalu null. Terlepas dari itu, tidak ada satu pun kode yang membaca config tersebut. |
| M-11 | `Archiving/MigrationArchiver.php` | Default archive path berada **di dalam** `database/migrations/`, dan mengabaikan `archiving.archive_directory` dari config. |
| M-12 | `Generation/SquashedMigrationGenerator.php` | `\'{$name}\'` di dalam heredoc menghasilkan backslash literal. `->collation('{\$column->collation}')` tidak terinterpolasi. Mapping tipe kolom terlalu dangkal (default fallback ke `string`). |
| M-13 | `Discovery/MigrationScanner.php` | `__construct(string $migrationPath = null)` menggunakan implicit nullable yang deprecated di PHP 8.4. |

### 2.4 Severity: Dokumentasi dan Integritas

| ID | Masalah |
|----|---------|
| D-1 | `tests/Feature/` kosong. Tidak ada satu pun file test, `phpunit.xml`, `Pest.php`, atau `TestCase`. README mengklaim "12 automated tests with 78%+ code coverage". |
| D-2 | `VERIFICATION_REPORT.md` menyatakan package production ready. Tidak akurat. |
| D-3 | Commit `6a4b98f` berjudul "feat: Add comprehensive test coverage for v1.0.0" padahal hanya menambahkan 4 file fixture. |
| D-4 | README campur bahasa Indonesia dan Inggris di tengah dokumen. |
| D-5 | Tidak ada `CHANGELOG.md`, CI workflow, `CONTRIBUTING.md`, atau `.gitattributes` (export-ignore). |

---

## 3. Tujuan

**G-1. Package benar-benar jalan.** `php artisan migrate:squash --dry-run` menyelesaikan seluruh 8 step tanpa error pada aplikasi Laravel dengan riwayat migration nyata.

**G-2. Sandbox terisolasi total.** Tidak ada satu pun query DDL yang mengenai koneksi default user. Ini requirement keamanan, bukan sekadar kebersihan kode.

**G-3. Verifikasi schema bermakna.** Perbandingan before/after benar-benar membandingkan kolom, tipe, nullable, default, index, dan foreign key. Diff yang dilaporkan menyebut detail konkret.

**G-4. Test suite Pest yang nyata.** Minimal coverage 70% pada `src/`, dengan test end-to-end yang menjalankan pipeline lengkap di SQLite in-memory.

**G-5. Dokumentasi jujur.** Setiap klaim di README bisa ditelusuri ke test yang lulus.

## 4. Non-Tujuan (v1.1.0)

- Dukungan PostgreSQL dan SQL Server. Fokus dulu ke SQLite dan MySQL.
- Squash yang mempertahankan data (package ini murni schema-level).
- Squash otomatis migration yang mengandung raw SQL. Guard akan menolak dan melaporkan, bukan mencoba menerjemahkan.
- GUI atau web dashboard.
- Merapikan `TableGrouping` menjadi fitur penuh. Lihat keputusan D-2 di bawah.

---

## 5. Keputusan Desain

**D-1. Sandbox pakai SQLite in-memory sebagai default, dengan koneksi terdaftar dinamis.**
Factory mendaftarkan config ke `config(['database.connections.{$name}' => $config])` lalu setiap komponen (`SandboxRunner`, `SchemaIntrospector`) menerima nama koneksi itu dan memanggil `DB::connection($name)`. Tidak ada satu pun pemanggilan `DB::` facade tanpa nama koneksi eksplisit di jalur sandbox.

**D-2. `TableGrouping` dihapus dari scope v1.1.0.**
Command sekarang tidak memakainya, dan pengurutan tabel sebenarnya sudah didapat gratis dari hasil introspeksi sandbox setelah semua migration asli dijalankan. Class ini dihapus, bukan diperbaiki. Circular FK ditangani di layer generator (lihat D-3).

**D-3. Circular FK ditangani dengan deferred FK migration.**
Generator menghasilkan `Schema::create()` per tabel tanpa foreign key, lalu satu file penutup `..._add_foreign_keys.php` yang berisi seluruh `Schema::table()->foreign()`. Pendekatan ini menghilangkan seluruh masalah urutan tanpa perlu topological sort.

**D-4. Generator berhenti pakai heredoc mentah.**
Ganti ke stub file `Generation/Stubs/squashed-table.stub` yang sudah ada di repo tapi belum pernah dibaca. Stub itu sudah punya placeholder `{{TABLE_NAME}}`, `{{COLUMNS}}`, `{{PRIMARY_KEY}}`, `{{INDEXES}}`, `{{FOREIGN_KEYS}}`. Generator cukup `file_get_contents($this->stubPath)` lalu `str_replace`. Perlu ditambah stub kedua untuk file foreign key (lihat D-3), dan `{{PRIMARY_KEY}}` harus bisa dihilangkan untuk tabel pivot yang tidak punya primary key tunggal.

**D-5. Introspeksi dipisah per driver dengan normalisasi eksplisit.**
Buat interface `IntrospectorDriver` dengan implementasi `SqliteIntrospector` dan `MysqlIntrospector`. Masing-masing bertanggung jawab menormalisasi output driver-specific menjadi bentuk kanonik yang sama sebelum dibungkus jadi `Column`/`Index`/`ForeignKey`. Ini yang menghilangkan H-5 sampai H-7 sekaligus.

**D-6. Object schema jadi mutable-by-construction.**
`Table` berhenti memakai `readonly` pada koleksi. Pilihannya: buat properti non-readonly, atau buat `withColumns()` yang mengembalikan instance baru. Rekomendasi: non-readonly untuk `Table`, tetap `readonly` untuk `Column`/`Index`/`ForeignKey` yang memang value object.

---

## 6. Requirements

### 6.1 Fase 1: Keamanan (blocker rilis)

| Req | Deskripsi | Menutup |
|-----|-----------|---------|
| FR-1.1 | `SandboxConnectionFactory` mendaftarkan config ke `database.connections` dengan nama unik dan mengembalikan nama itu. Method `create()` dijadikan static, atau semua call site dijadikan instance. Konsisten salah satu. | C-4, C-5 |
| FR-1.2 | `SandboxRunner` dan `SchemaIntrospector` wajib menerima nama koneksi di constructor dan memakai `DB::connection($name)` di setiap query. Tidak ada default `'sqlite'`. | C-4, M-4 |
| FR-1.3 | `SandboxRunner::run()` memanggil `Artisan::call('migrate', ['--database' => $name, '--path' => ..., '--realpath' => true, '--force' => true])`. | C-4 |
| FR-1.4 | `SandboxRunner::fresh()` tidak lagi memakai `SHOW TABLES` generik. Untuk SQLite in-memory cukup buang koneksi dan buat ulang. Untuk MySQL, drop dan create database sandbox. | C-4 |
| FR-1.5 | Tambahkan guard runtime: sebelum step apa pun yang menulis, command memverifikasi nama koneksi aktif berawalan `squash-sandbox-`. Kalau tidak, abort dengan error jelas. | C-4 |
| FR-1.6 | `SandboxRunner::run()` memperbaiki bug `$tempPath` yang direferensikan di blok `catch` padahal bisa belum ter-assign. | C-4 |

### 6.2 Fase 2: Bikin jalan

| Req | Deskripsi | Menutup |
|-----|-----------|---------|
| FR-2.1 | `handle()` di-refactor: setiap step menerima dan mengembalikan nilai secara eksplisit. Tidak ada variabel yang dipakai tanpa didefinisikan. | C-1 |
| FR-2.2 | `Table` bisa diisi kolom, index, dan FK tanpa readonly violation. | C-2 |
| FR-2.3 | Generator memakai stub + `str_replace`, menghasilkan PHP yang lolos `php -l`. | C-3, M-12 |
| FR-2.4 | Generator menghasilkan file FK terpisah untuk semua foreign key (lihat D-3). | M-7 |
| FR-2.5 | `Column::$autoIncrement` diubah jadi `bool` dengan default `false`. Semua call site disesuaikan. | H-9 |
| FR-2.6 | `Column::fromDb()` memperbaiki presedensi operator dan logika nullable, dan hanya menerima array yang sudah dinormalisasi (lihat D-5). | M-5, M-6 |
| FR-2.7 | `$this->success()` diganti `$this->info()`. | M-1 |
| FR-2.8 | Signature command diperbaiki: `{--driver= : sqlite atau mysql}` dan `{--table=* : ...}`. Validasi nilai `--driver` di awal `handle()`. | M-2, M-3 |
| FR-2.9 | `MigrationScanner` memperbaiki regex timestamp menjadi `/^(\d{4}_\d{2}_\d{2}_\d{6})/` dan mengganti implicit nullable jadi `?string`. | M-9, M-13 |
| FR-2.10 | Hapus `Discovery/TableGrouping.php` dan `MigrationScanner::extractTableInfo()`. | H-4, M-7, M-8 |

### 6.3 Fase 3: Verifikasi yang bermakna

| Req | Deskripsi | Menutup |
|-----|-----------|---------|
| FR-3.1 | Buat `Introspection/Drivers/SqliteIntrospector` dan `MysqlIntrospector` dengan output kanonik yang identik bentuknya. | H-6, H-7, D-5 |
| FR-3.2 | Introspeksi SQLite membaca foreign key lewat `PRAGMA foreign_key_list(table)`. | H-5 |
| FR-3.3 | Introspeksi SQLite membaca kolom index lewat `PRAGMA index_info(index)` untuk setiap entry dari `PRAGMA index_list`. | H-7 |
| FR-3.4 | Introspeksi MySQL berhenti memakai interpolasi string di query `INFORMATION_SCHEMA`. Pakai parameter binding. | keamanan |
| FR-3.5 | `SchemaDiff::add()` menyimpan `$details` sebagai array utuh (`['table' => ..., 'type' => ..., 'details' => $details]`), bukan lewat `compact` spread. `formatMessage()` disesuaikan untuk membaca dari `$diff['details']`. | C-6, H-8 |
| FR-3.6 | Perbandingan index dan FK tidak lagi mengandalkan nama yang di-generate database. Bandingkan berdasarkan signature (tabel + kolom terurut + tipe). | H-7 |
| FR-3.7 | Normalisasi tipe kolom sebelum dibandingkan (contoh `bigint(20) unsigned` dan `INTEGER` harus punya representasi kanonik yang sebanding). | akurasi |

### 6.4 Fase 4: Guard system

| Req | Deskripsi | Menutup |
|-----|-----------|---------|
| FR-4.1 | `FileChecker::scanMigration()` mengembalikan `$visitor->getResults()`. | H-1 |
| FR-4.2 | `SqlDetectorVisitor` memakai `$node->class` untuk `StaticCall`, dan menangani `Node\Name` maupun alias. | H-2 |
| FR-4.3 | `SqlDetectorVisitor` menerima `PrettyPrinter` lewat constructor. | H-3 |
| FR-4.4 | Deteksi data seeding memperbaiki traversal `DB::table('x')->insert()` dan mengekstrak nama tabel sebenarnya, bukan `'unknown'`. | H-2 |
| FR-4.5 | Migration yang kena guard **dikecualikan dari squash dan tidak diarsipkan**, bukan membuat seluruh proses gagal. Command melaporkan daftarnya di akhir. | UX |
| FR-4.6 | `ServiceProvider` memanggil `mergeConfigFrom()`, dan guard membaca `config('migrationsquash.guards.*')`. | M-10 |

### 6.5 Fase 5: Archiving

| Req | Deskripsi | Menutup |
|-----|-----------|---------|
| FR-5.1 | `MigrationArchiver` membaca `archiving.archive_directory` dari config. | M-11 |
| FR-5.2 | Default archive path dipindah ke `database/migrations-archive/{timestamp}/`, di luar folder yang dipindai Laravel. | M-11 |
| FR-5.3 | Sebelum arsip, command menulis manifest `archive-manifest.json` berisi daftar file asli, hash, dan timestamp, supaya bisa di-rollback. | keamanan |
| FR-5.4 | Tambah command `migrate:squash:restore` yang mengembalikan arsip terakhir berdasarkan manifest. | UX |

### 6.6 Fase 6: Test suite

| Req | Deskripsi |
|-----|-----------|
| FR-6.1 | Tambah `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php` berbasis `orchestra/testbench` (masuk `require-dev`). |
| FR-6.2 | Unit test: `MigrationScanner`, `SqliteIntrospector`, `MysqlIntrospector` (di-skip kalau MySQL tidak tersedia), `SquashedMigrationGenerator`, `SchemaComparator`, `SchemaDiff`, `FileChecker`, `MigrationArchiver`. |
| FR-6.3 | Feature test end-to-end: jalankan `migrate:squash --dry-run` terhadap tiap fixture di `tests/MigrationSquashFixtures/` dan assert exit code sukses serta diff kosong. |
| FR-6.4 | Custom expectation `toMatchSchema()` yang membandingkan dua snapshot dan menampilkan diff yang terbaca kalau gagal. |
| FR-6.5 | Test regresi keamanan: assert bahwa setelah `migrate:squash` dijalankan, koneksi default **tidak tersentuh sama sekali** (tabel di koneksi default tetap utuh). Ini test paling penting di suite. |
| FR-6.6 | Test bahwa setiap file yang di-generate lolos `php -l`. |
| FR-6.7 | Fixture baru: tabel tanpa kolom `id`, composite primary key, composite unique index, enum, kolom dengan default, self-referencing FK. |
| FR-6.8 | Coverage minimal 70% pada `src/`, diukur oleh CI, bukan diklaim manual. |

### 6.7 Fase 7: Rilis

| Req | Deskripsi | Menutup |
|-----|-----------|---------|
| FR-7.1 | Hapus atau tulis ulang `VERIFICATION_REPORT.md` sesuai kondisi sebenarnya. | D-2 |
| FR-7.2 | README ditulis ulang penuh dalam satu bahasa (rekomendasi: Inggris, karena target Packagist internasional). Semua klaim jumlah test dan coverage diambil dari output CI. | D-1, D-4 |
| FR-7.3 | Tambah `CHANGELOG.md` dengan entry v1.1.0 yang menyebut secara eksplisit bahwa v1.0.0 tidak fungsional. | D-5 |
| FR-7.4 | Tambah GitHub Actions: matrix PHP 8.1 sampai 8.4 x Laravel 10 sampai 12, jalankan Pest dan Pint, laporkan coverage. | D-5 |
| FR-7.5 | Tambah `.gitattributes` dengan `export-ignore` untuk `tests/`, `.github/`, dan file dev lainnya. | D-5 |
| FR-7.6 | Tandai v1.0.0 sebagai abandoned di GitHub release notes, dan tulis di README bahwa v1.0.0 jangan dipakai. | integritas |

---

## 7. Acceptance Criteria

Rilis v1.1.0 hanya boleh dilakukan kalau **semua** poin ini terpenuhi:

1. `vendor/bin/pest` hijau di PHP 8.1, 8.2, 8.3, dan 8.4.
2. Coverage `src/` minimal 70% menurut output CI.
3. FR-6.5 (test isolasi sandbox) lulus. Tidak ada exception.
4. `migrate:squash --dry-run` selesai sukses pada aplikasi Laravel nyata dengan minimal 20 file migration.
5. Setiap file migration yang di-generate lolos `php -l` dan bisa dijalankan `migrate` dari kondisi database kosong.
6. Diff schema kosong pada seluruh fixture.
7. `grep -ri "12 automated tests\|78%" README.md VERIFICATION_REPORT.md` tidak menghasilkan apa-apa, atau angkanya sesuai output CI.
8. Tidak ada pemanggilan `DB::` facade tanpa `connection()` eksplisit di dalam `src/MigrationSquash/Sandbox/` dan `src/MigrationSquash/Introspection/`.

---

## 8. Urutan Pengerjaan

| Fase | Isi | Estimasi | Blocking |
|------|-----|----------|----------|
| 1 | Keamanan sandbox (FR-1.x) | 1 hari | Ya, semua fase lain |
| 2 | Bikin jalan (FR-2.x) | 1 sampai 2 hari | Ya |
| 3 | Verifikasi bermakna (FR-3.x) | 2 hari | Ya |
| 6a | Setup test infra + test isolasi (FR-6.1, FR-6.5) | 0.5 hari | Kerjakan paralel dengan fase 2 |
| 4 | Guard system (FR-4.x) | 1 hari | Tidak |
| 5 | Archiving + restore (FR-5.x) | 1 hari | Tidak |
| 6b | Sisa test suite (FR-6.2 sampai 6.8) | 2 hari | Ya untuk rilis |
| 7 | Dokumentasi + CI + rilis (FR-7.x) | 1 hari | Ya untuk rilis |

Total sekitar 9 sampai 10 hari kerja.

**Catatan urutan:** FR-6.1 dan FR-6.5 sengaja ditarik ke depan. Test isolasi sandbox harus ada sebelum kode sandbox disentuh, supaya perbaikannya terbukti, bukan diasumsikan.

---

## 9. Risiko

| Risiko | Dampak | Mitigasi |
|--------|--------|----------|
| Ada user yang sudah install v1.0.0 | Rendah. Package fatal sebelum menyentuh database, jadi tidak ada yang rusak. | Tulis jelas di CHANGELOG dan release notes. |
| Normalisasi tipe SQLite vs MySQL ternyata tidak bisa dibandingkan 1:1 | Verifikasi jadi terlalu longgar atau terlalu ketat | Sediakan `verification.ignore_differences` di config (sudah ada di config, tinggal diimplementasikan) dan dokumentasikan batasannya secara jujur. |
| Squash pada aplikasi dengan riwayat migration yang sangat panjang lambat | UX buruk | Ukur di fase 3. Kalau lambat, tambahkan progress bar. Bukan blocker rilis. |
| Scope creep ke PostgreSQL | Rilis molor | Sudah masuk non-tujuan. Tahan sampai v1.2.0. |

---

## 10. Yang Sengaja Ditunda ke v1.2.0

- Dukungan PostgreSQL.
- Squash parsial berdasarkan rentang tanggal (`--before=2024_01_01`).
- Integrasi dengan `migrate:status` untuk mendeteksi migration yang sudah jalan di production.
- Mode interaktif untuk memilih tabel mana yang di-squash.
