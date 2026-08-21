<?php

namespace MigrationSquash\Guards;

class RawSqlDetector
{
    protected FileChecker $fileChecker;

    public function __construct(?FileChecker $fileChecker = null)
    {
        $this->fileChecker = $fileChecker ?? new FileChecker();
    }

    /**
     * @param array<string> $migrationFiles
     * @return array<string, string> List of file => reason pairs that should be rejected
     */
    public function detect(array $migrationFiles): array
    {
        $rejected = [];

        foreach ($migrationFiles as $file) {
            try {
                $result = $this->fileChecker->scanMigration($file);
                
                if (! empty($result['rawSql'])) {
                    $statements = implode(", ", array_column($result['rawSql'], 'statement'));
                    $rejected[$file] = "Contains raw SQL: {$statements}";
                }
            } catch (\Throwable $e) {
                $rejected[$file] = "Failed to scan: {$e->getMessage()}";
            }
        }

        return $rejected;
    }
}
