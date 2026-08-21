<?php

namespace MigrationSquash\Sandbox;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SandboxRunner
{
    protected string $connectionName;

    public function __construct(string $connectionName = 'sqlite')
    {
        $this->connectionName = $connectionName;
    }

    /**
     * Run all migrations on the given files against the sandbox connection
     * 
     * @param  array<string>  $migrationFiles
     * @return bool Success flag
     */
    public function run(array $migrationFiles): bool
    {
        // Create a custom migration path
        $originalMigrationPath = config('database.migrations');
        
        try {
            // Set up temporary migration path
            $tempPath = sys_get_temp_dir() . '/migrate-squash-' . uniqid();
            mkdir($tempPath, 0755, true);
            
            foreach ($migrationFiles as $file) {
                $filename = basename($file);
                copy($file, $tempPath . '/' . $filename);
            }
            
            // Run migrations with --path option and our connection
            Artisan::call('migrate', [
                '--path' => $tempPath,
                '--force' => true,
            ]);
            
            // Clean up
            $this->deleteDirectory($tempPath);
            
            return true;
        } catch (\Throwable $e) {
            // Clean up even on failure
            $this->deleteDirectory($tempPath ?? '');
            throw new \RuntimeException("Failed to run sandbox migrations: {$e->getMessage()}");
        }
    }

    /**
     * Fresh start the sandbox (drop all tables first)
     */
    public function fresh(): void
    {
        DB::statement('DROP TABLE IF EXISTS migrations');
        
        // Try to drop all other tables
        try {
            // This is driver-specific and may need adjustment per database
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            // Drop all tables one by one
            $tables = DB::select("SHOW TABLES");
            foreach ($tables as $tableObj) {
                $tableName = $tableObj->{array_key_first((array)$tableObj)};
                if ($tableName !== 'migrations') {
                    DB::statement("DROP TABLE IF EXISTS `{$tableName}`");
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } catch (\Throwable $e) {
            // SQLite or other drivers might have different syntax
            // Just ignore errors during cleanup
        }
    }

    /**
     * Cleanup sandbox connection
     */
    public function cleanup(): void
    {
        // Switch back to default connection and drop sqlite in-memory database
        DB::disconnect($this->connectionName);
    }

    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
