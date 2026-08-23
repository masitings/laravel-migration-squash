<?php

namespace MigrationSquash\Guards;

class DataSeedDetector
{
    protected FileChecker $fileChecker;

    public function __construct(?FileChecker $fileChecker = null)
    {
        $this->fileChecker = $fileChecker ?? new FileChecker;
    }

    /**
     * @param  array<string>  $migrationFiles
     * @return array<string, string> List of file => reason pairs that should be rejected
     */
    public function detect(array $migrationFiles): array
    {
        $rejected = [];

        foreach ($migrationFiles as $file) {
            try {
                $result = $this->fileChecker->scanMigration($file);

                if (! empty($result['dataSeeding'])) {
                    $actions = implode(', ', array_map(function ($a) {
                        return "{$a['type']}({$a['table']})";
                    }, $result['dataSeeding']));
                    $rejected[$file] = "Contains data seeding: {$actions}";
                }
            } catch (\Throwable $e) {
                $rejected[$file] = "Failed to scan: {$e->getMessage()}";
            }
        }

        return $rejected;
    }
}
