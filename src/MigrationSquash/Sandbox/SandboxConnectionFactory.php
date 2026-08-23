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
        if ($type === 'mysql') {
            return static::createMySQL();
        }

        return static::createSQLite();
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
            DB::connection($bootstrapName)->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
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
     * Drop the sandbox database (MySQL only), disconnect, and remove the config.
     */
    public static function destroy(string $connectionName): void
    {
        $driver = config("database.connections.{$connectionName}.driver");
        $database = config("database.connections.{$connectionName}.database");

        // Only ever drop a database this factory created. The prefix check is
        // the last line of defence against dropping a real database.
        if ($driver === 'mysql'
            && is_string($database)
            && str_starts_with($database, static::SANDBOX_DB_PREFIX)
        ) {
            try {
                DB::connection($connectionName)->statement("DROP DATABASE IF EXISTS `{$database}`");
            } catch (\Throwable $e) {
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
