<?php

namespace MigrationSquash\Discovery;

class MigrationScanner
{
    protected string $migrationPath;

    public function __construct(?string $migrationPath = null)
    {
        $this->migrationPath = $migrationPath ?? database_path('migrations');
    }

    /**
     * Scan the migration directory and return sorted migration file info.
     *
     * @return array<int, array{file: string, timestamp: string, name: string}>
     */
    public function scan(): array
    {
        if (! is_dir($this->migrationPath)) {
            throw new \RuntimeException("Migration directory not found: {$this->migrationPath}");
        }

        $files = glob($this->migrationPath.'/*.php');

        if ($files === false) {
            return [];
        }

        $migrations = [];

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $name = basename($file, '.php');

            // Extract timestamp from Laravel migration filename format:
            // 2024_01_01_000001_create_users_table.php
            preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})/', $name, $matches);
            $timestamp = $matches[1] ?? '0000_00_00_000000';

            $migrations[] = [
                'file' => $file,
                'timestamp' => $timestamp,
                'name' => $name,
            ];
        }

        // Sort by timestamp (Laravel's default sorting)
        usort($migrations, function ($a, $b) {
            return strcmp($a['timestamp'], $b['timestamp']);
        });

        return $migrations;
    }
}
