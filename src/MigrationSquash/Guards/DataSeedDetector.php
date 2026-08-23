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
     * Detect offending migrations, honouring the `guards.block_data_seeding`
     * config flag. Returns an empty array when the guard is switched off.
     *
     * @param  array<string>  $migrationFiles
     * @return array<string, string> List of file => reason pairs that should be rejected
     */
    public function detect(array $migrationFiles): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        return $this->detectAll($migrationFiles);
    }

    /**
     * Is this guard switched on?
     */
    public function isEnabled(): bool
    {
        return (bool) config('migrationsquash.guards.block_data_seeding', true);
    }

    /**
     * Detect offending migrations regardless of configuration.
     *
     * `migrate:squash --check` uses this so the diagnostic always reports the
     * truth even when the guard is switched off for the squash itself.
     *
     * @param  array<string>  $migrationFiles
     * @return array<string, string> List of file => reason pairs
     */
    public function detectAll(array $migrationFiles): array
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
