<?php

namespace MigrationSquash\Archiving;

use Illuminate\Support\Facades\File;

class MigrationArchiver
{
    protected string $sourcePath;

    protected string $archivePath;

    public function __construct(?string $sourcePath = null, ?string $archivePath = null)
    {
        $this->sourcePath = $sourcePath ?? database_path('migrations');
        $this->archivePath = $archivePath ?? $this->resolveArchivePath();
    }

    /**
     * Resolve archive path from config or use default.
     * Default is outside database/migrations/ so Laravel doesn't scan it.
     */
    protected function resolveArchivePath(): string
    {
        $configDir = config('migrationsquash.archiving.archive_directory');

        // Default sits next to the migration folder wherever that folder
        // actually is, so a customised database path stays consistent.
        $default = dirname(rtrim($this->sourcePath, '/')).'/migrations-archive';

        $base = ! empty($configDir) ? base_path($configDir) : $default;

        // Refuse an archive directory nested inside database/migrations.
        // A published config from an older version can still point there, and
        // archived files under the migration path get picked up as live
        // migrations, which is exactly what archiving is meant to prevent.
        $migrationsPath = rtrim($this->sourcePath, '/').'/';

        if (str_starts_with(rtrim($base, '/').'/', $migrationsPath)) {
            $base = $default;
        }

        return rtrim($base, '/').'/'.date('Y_m_d_His');
    }

    /**
     * Archive migration files to a timestamped folder.
     *
     * Writes an archive-manifest.json before moving files for rollback safety.
     *
     * @param  array<string>  $migrationFiles  List of file paths to archive
     * @return bool Success flag
     */
    public function archive(array $migrationFiles): bool
    {
        try {
            File::ensureDirectoryExists($this->archivePath);

            // Write manifest before archiving for rollback capability
            $this->writeManifest($migrationFiles);

            foreach ($migrationFiles as $file) {
                if (! file_exists($file)) {
                    continue;
                }

                $filename = basename($file);
                $destination = $this->archivePath.'/'.$filename;

                File::move($file, $destination);
            }

            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to archive migrations: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Write an archive manifest for rollback.
     *
     * @param  array<string>  $migrationFiles
     */
    protected function writeManifest(array $migrationFiles): void
    {
        $manifest = [
            'archived_at' => now()->toIso8601String(),
            'source_path' => $this->sourcePath,
            'archive_path' => $this->archivePath,
            'files' => [],
        ];

        foreach ($migrationFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $manifest['files'][] = [
                'original_path' => $file,
                'filename' => basename($file),
                'hash' => md5_file($file),
                'size' => filesize($file),
            ];
        }

        file_put_contents(
            $this->archivePath.'/archive-manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Preview what will be archived without actually moving files.
     *
     * @param  array<string>  $migrationFiles  List of file paths to preview
     * @return array{archivePath: string, filesToArchive: int, estimatedSize: int}
     */
    public function preview(array $migrationFiles): array
    {
        $totalSize = 0;

        foreach ($migrationFiles as $file) {
            if (file_exists($file)) {
                $totalSize += filesize($file);
            }
        }

        return [
            'archivePath' => $this->archivePath,
            'filesToArchive' => count($migrationFiles),
            'estimatedSize' => $totalSize,
        ];
    }

    /**
     * Get the archive path.
     */
    public function getArchivePath(): string
    {
        return $this->archivePath;
    }
}
