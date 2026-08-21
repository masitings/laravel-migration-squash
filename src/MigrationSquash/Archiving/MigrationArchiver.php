<?php

namespace MigrationSquash\Archiving;

use Illuminate\Support\Facades\File;

class MigrationArchiver
{
    protected string $sourcePath;
    protected string $archivePath;

    public function __construct(string $sourcePath = null, string $archivePath = null)
    {
        $this->sourcePath = $sourcePath ?? database_path('migrations');
        $this->archivePath = $archivePath ?? database_path('migrations/archive/' . date('Y_m_d_His'));
    }

    /**
     * Archive migration files to a timestamped folder
     * 
     * @param  array<string>  $migrationFiles  List of file paths to archive
     * @return bool Success flag
     */
    public function archive(array $migrationFiles): bool
    {
        try {
            // Create archive directory if it doesn't exist
            File::ensureDirectoryExists($this->archivePath);
            
            // Move each file to archive
            foreach ($migrationFiles as $file) {
                if (! file_exists($file)) {
                    continue;
                }
                
                $filename = basename($file);
                $destination = $this->archivePath . '/' . $filename;
                
                File::move($file, $destination);
            }
            
            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to archive migrations: {$e->getMessage()}");
        }
    }

    /**
     * Preview what will be archived without actually moving files
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
     * Get archive path for a given date
     */
    public function getArchivePathForDate(string $date): string
    {
        return database_path('migrations/archive/' . $date);
    }
}
