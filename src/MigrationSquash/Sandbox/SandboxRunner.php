<?php

namespace MigrationSquash\Sandbox;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SandboxRunner
{
    protected string $connectionName;

    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
        $this->assertSandboxConnection();
    }

    /**
     * Guard: verify the connection name starts with 'squash-sandbox-'.
     * Prevents accidental writes to the user's real database.
     */
    protected function assertSandboxConnection(): void
    {
        if (! str_starts_with($this->connectionName, 'squash-sandbox-')) {
            throw new \RuntimeException(
                "Sandbox safety check failed: connection name '{$this->connectionName}' ".
                "does not start with 'squash-sandbox-'. Aborting to protect your database."
            );
        }
    }

    /**
     * Run all migrations on the given files against the sandbox connection.
     *
     * @param  array<string>  $migrationFiles
     * @return bool Success flag
     */
    public function run(array $migrationFiles): bool
    {
        $tempPath = null;

        try {
            $tempPath = sys_get_temp_dir().'/migrate-squash-'.uniqid();
            mkdir($tempPath, 0755, true);

            foreach ($migrationFiles as $file) {
                $filename = basename($file);
                copy($file, $tempPath.'/'.$filename);
            }

            Artisan::call('migrate', [
                '--database' => $this->connectionName,
                '--path' => $tempPath,
                '--realpath' => true,
                '--force' => true,
                '--no-interaction' => true,
            ]);

            $this->deleteDirectory($tempPath);

            return true;
        } catch (\Throwable $e) {
            if ($tempPath !== null) {
                $this->deleteDirectory($tempPath);
            }

            throw new \RuntimeException("Failed to run sandbox migrations: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Roll the given migrations back against the sandbox connection.
     *
     * Used to prove the generated down() methods work. Nothing in the squash
     * flow itself rolls back, which is precisely why a broken dropForeign()
     * went unnoticed for so long.
     *
     * @param  array<string>  $migrationFiles
     */
    public function rollback(array $migrationFiles): bool
    {
        $tempPath = null;

        try {
            $tempPath = sys_get_temp_dir().'/migrate-squash-rollback-'.uniqid();
            mkdir($tempPath, 0755, true);

            foreach ($migrationFiles as $file) {
                copy($file, $tempPath.'/'.basename($file));
            }

            Artisan::call('migrate:rollback', [
                '--database' => $this->connectionName,
                '--path' => $tempPath,
                '--realpath' => true,
                '--step' => 0,
                '--force' => true,
            ]);

            $this->deleteDirectory($tempPath);

            return true;
        } catch (\Throwable $e) {
            if ($tempPath !== null) {
                $this->deleteDirectory($tempPath);
            }

            throw new \RuntimeException("Failed to roll back sandbox migrations: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Fresh start the sandbox (drop all tables).
     *
     * For SQLite in-memory: disconnect and reconnect (cheapest reset).
     * For MySQL: drop all tables using the sandbox connection.
     */
    public function fresh(): void
    {
        $driver = config("database.connections.{$this->connectionName}.driver");

        match ($driver) {
            'sqlite' => $this->freshSQLite(),
            'mysql' => $this->freshMySQL(),
            'pgsql' => $this->freshPostgres(),
            'sqlsrv' => $this->freshSqlServer(),
            default => throw new \RuntimeException("Unsupported driver '{$driver}' for sandbox reset."),
        };
    }

    /**
     * Reset SQLite in-memory by disconnecting and reconnecting.
     */
    protected function freshSQLite(): void
    {
        DB::disconnect($this->connectionName);
        // Reconnecting to :memory: gives a fresh empty database
        DB::connection($this->connectionName)->getPdo();
    }

    /**
     * Reset MySQL by dropping all tables in the sandbox database.
     */
    protected function freshMySQL(): void
    {
        $connection = DB::connection($this->connectionName);

        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $tables = $connection->select('SHOW TABLES');

            foreach ($tables as $tableObj) {
                $tableName = $tableObj->{array_key_first((array) $tableObj)};
                $connection->statement("DROP TABLE IF EXISTS `{$tableName}`");
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Reset PostgreSQL by dropping all tables in current schema.
     */
    protected function freshPostgres(): void
    {
        $connection = DB::connection($this->connectionName);

        $tables = $connection->select("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = current_schema()
              AND table_type = 'BASE TABLE'
        ");

        if (empty($tables)) {
            return;
        }

        $tableNames = array_map(fn ($t) => '"'.$t->table_name.'"', $tables);
        $sql = 'DROP TABLE IF EXISTS '.implode(', ', $tableNames).' CASCADE';
        $connection->statement($sql);
    }

    /**
     * Reset SQL Server by dropping foreign keys then dropping all tables.
     */
    protected function freshSqlServer(): void
    {
        $connection = DB::connection($this->connectionName);

        $fks = $connection->select('
            SELECT fk.name AS constraint_name, t.name AS table_name
            FROM sys.foreign_keys fk
            JOIN sys.tables t ON fk.parent_object_id = t.object_id
        ');

        foreach ($fks as $fk) {
            $connection->statement("ALTER TABLE [{$fk->table_name}] DROP CONSTRAINT [{$fk->constraint_name}]");
        }

        $tables = $connection->select("
            SELECT name FROM sys.tables WHERE type = 'U'
        ");

        foreach ($tables as $t) {
            $connection->statement("DROP TABLE IF EXISTS [{$t->name}]");
        }
    }

    /**
     * Cleanup sandbox connection.
     */
    public function cleanup(): void
    {
        DB::disconnect($this->connectionName);
    }

    /**
     * Recursively delete a directory.
     */
    protected function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
