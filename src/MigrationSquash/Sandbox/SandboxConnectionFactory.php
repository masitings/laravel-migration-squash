<?php

namespace MigrationSquash\Sandbox;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

class SandboxConnectionFactory
{
    /**
     * Prefix for sandbox database names. destroy() refuses to drop any
     * database whose name does not start with this.
     */
    public const SANDBOX_DB_PREFIX = 'laravel_squash_';

    /**
     * Create a temporary database connection for sandbox testing.
     *
     * Registers the connection in Laravel's database config and returns the
     * connection name. All downstream components must use this name with
     * DB::connection($name) — never the default connection.
     *
     * @param  string  $type  Either 'sqlite' or 'mysql'
     * @return string The registered connection name (prefixed with 'squash-sandbox-')
     */
    public static function create(string $type = 'sqlite'): string
    {
        return match ($type) {
            'sqlite' => static::createSQLite(),
            'mysql' => static::createMySQL(),
            'pgsql' => static::createPostgres(),
            'sqlsrv' => static::createSqlServer(),
            default => throw new \InvalidArgumentException("Unsupported sandbox driver '{$type}'."),
        };
    }

    /**
     * Create and register a SQLite in-memory sandbox connection.
     */
    protected static function createSQLite(): string
    {
        $name = 'squash-sandbox-'.uniqid();

        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];

        Config::set("database.connections.{$name}", $config);

        return $name;
    }

    /**
     * Create and register a MySQL temporary database sandbox connection.
     */
    protected static function createMySQL(): string
    {
        $dbName = static::SANDBOX_DB_PREFIX.uniqid();
        $name = 'squash-sandbox-mysql-'.uniqid();

        $config = [
            'driver' => 'mysql',
            'host' => config('migrationsquash.sandbox.mysql_host', env('DB_HOST', '127.0.0.1')),
            'port' => config('migrationsquash.sandbox.mysql_port', env('DB_PORT', '3306')),
            'database' => $dbName,
            'username' => config('migrationsquash.sandbox.mysql_username', env('DB_USERNAME', 'root')),
            'password' => config('migrationsquash.sandbox.mysql_password', env('DB_PASSWORD', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];

        // The sandbox database does not exist yet. Connect without selecting a
        // database and create it, otherwise every later query fails with
        // "Unknown database".
        $bootstrapName = $name.'-bootstrap';
        Config::set("database.connections.{$bootstrapName}", array_merge($config, ['database' => null]));

        try {
            // Create the sandbox database with the SAME charset and collation
            // the connection uses. Without this the database default differs
            // from what Laravel stamps on each table, so every string column
            // looks like it carries a non-default collation and the generated
            // migrations fill up with redundant ->collation() calls.
            $charset = $config['charset'];
            $collation = $config['collation'];

            DB::connection($bootstrapName)->statement(
                "CREATE DATABASE IF NOT EXISTS `{$dbName}` ".
                "DEFAULT CHARACTER SET {$charset} COLLATE {$collation}"
            );
        } catch (\Throwable $e) {
            Config::set("database.connections.{$bootstrapName}", null);

            throw new \RuntimeException(
                "Could not create MySQL sandbox database '{$dbName}': {$e->getMessage()}. ".
                'The configured MySQL user needs CREATE DATABASE privileges, '.
                'or use the default SQLite sandbox instead.',
                0,
                $e,
            );
        } finally {
            DB::disconnect($bootstrapName);
        }

        Config::set("database.connections.{$bootstrapName}", null);
        Config::set("database.connections.{$name}", $config);

        return $name;
    }

    /**
     * Create and register a PostgreSQL temporary database sandbox connection.
     */
    protected static function createPostgres(): string
    {
        $dbName = static::SANDBOX_DB_PREFIX.uniqid();
        $name = 'squash-sandbox-pgsql-'.uniqid();

        $config = [
            'driver' => 'pgsql',
            'host' => config('migrationsquash.sandbox.pgsql_host', env('DB_HOST', '127.0.0.1')),
            'port' => config('migrationsquash.sandbox.pgsql_port', env('DB_PORT', '5432')),
            'database' => $dbName,
            'username' => config('migrationsquash.sandbox.pgsql_username', env('DB_USERNAME', 'postgres')),
            'password' => config('migrationsquash.sandbox.pgsql_password', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];

        $bootstrapName = $name.'-bootstrap';
        Config::set("database.connections.{$bootstrapName}", array_merge($config, ['database' => 'postgres']));

        try {
            DB::connection($bootstrapName)->statement("CREATE DATABASE \"{$dbName}\" ENCODING 'UTF8'");
        } catch (\Throwable $e) {
            Config::set("database.connections.{$bootstrapName}", null);

            throw new \RuntimeException(
                "Could not create PostgreSQL sandbox database '{$dbName}': {$e->getMessage()}. ".
                'The configured PostgreSQL user needs CREATEDB privileges, '.
                'or use the default SQLite sandbox instead.',
                0,
                $e,
            );
        } finally {
            DB::disconnect($bootstrapName);
        }

        Config::set("database.connections.{$bootstrapName}", null);
        Config::set("database.connections.{$name}", $config);

        return $name;
    }

    /**
     * Create and register a SQL Server temporary database sandbox connection.
     */
    protected static function createSqlServer(): string
    {
        $dbName = static::SANDBOX_DB_PREFIX.uniqid();
        $name = 'squash-sandbox-sqlsrv-'.uniqid();

        $config = [
            'driver' => 'sqlsrv',
            'host' => config('migrationsquash.sandbox.sqlsrv_host', env('DB_HOST', '127.0.0.1')),
            'port' => config('migrationsquash.sandbox.sqlsrv_port', env('DB_PORT', '1433')),
            'database' => $dbName,
            'username' => config('migrationsquash.sandbox.sqlsrv_username', env('DB_USERNAME', 'sa')),
            'password' => config('migrationsquash.sandbox.sqlsrv_password', env('DB_PASSWORD', '')),
            'charset' => 'utf8',
            'collation' => 'SQL_Latin1_General_CP1_CI_AS',
            'prefix' => '',
        ];

        $bootstrapName = $name.'-bootstrap';
        Config::set("database.connections.{$bootstrapName}", array_merge($config, ['database' => 'master']));

        try {
            DB::connection($bootstrapName)->statement("CREATE DATABASE [{$dbName}]");
        } catch (\Throwable $e) {
            Config::set("database.connections.{$bootstrapName}", null);

            throw new \RuntimeException(
                "Could not create SQL Server sandbox database '{$dbName}': {$e->getMessage()}. ".
                'The configured SQL Server user needs CREATE ANY DATABASE privileges, '.
                'or use the default SQLite sandbox instead.',
                0,
                $e,
            );
        } finally {
            DB::disconnect($bootstrapName);
        }

        Config::set("database.connections.{$bootstrapName}", null);
        Config::set("database.connections.{$name}", $config);

        return $name;
    }

    /**
     * Drop the sandbox database, disconnect, and remove the config.
     */
    public static function destroy(string $connectionName): void
    {
        $driver = config("database.connections.{$connectionName}.driver");
        $database = config("database.connections.{$connectionName}.database");

        // Only ever drop a database this factory created. The prefix check is
        // the last line of defence against dropping a real database.
        if (is_string($database) && str_starts_with($database, static::SANDBOX_DB_PREFIX)) {
            try {
                if ($driver === 'mysql') {
                    DB::connection($connectionName)->statement("DROP DATABASE IF EXISTS `{$database}`");
                } elseif ($driver === 'pgsql') {
                    $bootstrapName = $connectionName.'-destroy-bootstrap';
                    $config = config("database.connections.{$connectionName}");
                    if (is_array($config)) {
                        Config::set("database.connections.{$bootstrapName}", array_merge($config, ['database' => 'postgres']));
                        DB::connection($bootstrapName)->statement("
                            SELECT pg_terminate_backend(pid)
                            FROM pg_stat_activity
                            WHERE datname = '{$database}' AND pid <> pg_backend_pid()
                        ");
                        DB::connection($bootstrapName)->statement("DROP DATABASE IF EXISTS \"{$database}\"");
                        DB::disconnect($bootstrapName);
                        Config::set("database.connections.{$bootstrapName}", null);
                    }
                } elseif ($driver === 'sqlsrv') {
                    $bootstrapName = $connectionName.'-destroy-bootstrap';
                    $config = config("database.connections.{$connectionName}");
                    if (is_array($config)) {
                        Config::set("database.connections.{$bootstrapName}", array_merge($config, ['database' => 'master']));
                        DB::connection($bootstrapName)->statement("ALTER DATABASE [{$database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
                        DB::connection($bootstrapName)->statement("DROP DATABASE [{$database}]");
                        DB::disconnect($bootstrapName);
                        Config::set("database.connections.{$bootstrapName}", null);
                    }
                }
            } catch (\Throwable) {
                // Best effort. A leftover sandbox database is harmless.
            }
        }

        DB::disconnect($connectionName);
        Config::set("database.connections.{$connectionName}", null);
    }

    /**
     * Detect if we need MySQL instead of SQLite based on migration content.
     *
     * @param  array<string>  $migrationFiles
     * @return bool True if MySQL is required
     */
    public static function needsMySQL(array $migrationFiles): bool
    {
        $parserFactory = new ParserFactory;
        $parser = $parserFactory->createForNewestSupportedVersion();

        foreach ($migrationFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $code = file_get_contents($file);

            try {
                $stmts = $parser->parse($code);

                $visitor = new class extends NodeVisitorAbstract
                {
                    public bool $requiresMySQL = false;

                    public function enterNode(Node $node): void
                    {
                        // Look for MySQL-specific column types
                        if (
                            $node instanceof MethodCall &&
                            $node->name instanceof Identifier &&
                            in_array($node->name->name, ['enum', 'geometry', 'point', 'linestring', 'polygon'])
                        ) {
                            $this->requiresMySQL = true;
                        }
                    }
                };

                $traverser = new NodeTraverser;
                $traverser->addVisitor($visitor);
                $traverser->traverse($stmts ?? []);

                if ($visitor->requiresMySQL) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return false;
    }
}
