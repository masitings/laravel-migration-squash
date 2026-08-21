<?php

namespace MigrationSquash\Sandbox;

class SandboxConnectionFactory
{
    /**
     * Create a temporary database connection for sandbox testing
     * 
     * @param string $type Either 'sqlite' or 'mysql'
     * @return array{name: string, config: array<string, mixed>}
     */
    public function create(string $type = 'sqlite'): array
    {
        if ($type === 'mysql') {
            return $this->createMySQL();
        }
        
        // Default to SQLite in-memory
        return $this->createSQLite();
    }

    /**
     * Create SQLite in-memory connection
     */
    protected function createSQLite(): array
    {
        $name = 'squash-sandbox-' . uniqid();
        
        // Configure SQLite in-memory database
        $config = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ];
        
        return [
            'name' => $name,
            'config' => $config,
        ];
    }

    /**
     * Create MySQL temporary database connection
     * This would typically use Docker container in production
     */
    protected function createMySQL(): array
    {
        // For now, we'll use a temporary database name
        $dbName = 'laravel_squash_' . uniqid();
        
        $config = [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => $dbName,
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];
        
        // In real implementation, you'd create this database via Docker or direct connection
        // DB::connection()->getPdo()->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
        
        return [
            'name' => 'squash-sandbox-mysql-' . uniqid(),
            'config' => $config,
        ];
    }

    /**
     * Detect if we need MySQL instead of SQLite based on migration content
     * 
     * @param array<string> $migrationFiles
     * @return bool True if MySQL is required
     */
    public static function needsMySQL(array $migrationFiles): bool
    {
        $parserFactory = new \PhpParser\ParserFactory();
        $parser = $parserFactory->createForNewestSupportedVersion();
        
        $requiresMySQL = false;
        
        foreach ($migrationFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }
            
            $code = file_get_contents($file);
            
            try {
                $stmts = $parser->parse($code);
                
                $visitor = new class extends \PhpParser\NodeVisitorAbstract {
                    public bool $requiresMySQL = false;
                    
                    public function enterNode(\PhpParser\Node $node): void
                    {
                        // Look for MySQL-specific types
                        if (
                            $node instanceof \PhpParser\Node\MethodCall &&
                            in_array($node->name->name, ['enum', 'geometry', 'point', 'linestring', 'polygon'])
                        ) {
                            $this->requiresMySQL = true;
                        }
                        
                        // Look for DB::statement with MySQL-specific syntax
                        if (
                            $node instanceof \PhpParser\Node\Expr\StaticCall
                        ) {
                            if (
                                ($node->var instanceof \PhpParser\Node\Identifier && 
                                 $node->var->name === 'DB') &&
                                $node->name instanceof \PhpParser\Node\Identifier &&
                                in_array($node->name->name, ['statement', 'unprepared'])
                            ) {
                                // Check if statement contains MySQL-specific features
                                // This is a simplified check - should be more thorough
                            }
                        }
                    }
                };
                
                $traverser = new \PhpParser\NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($stmts ?? []);
                
                if ($visitor->requiresMySQL) {
                    $requiresMySQL = true;
                    break;
                }
                
            } catch (\Throwable $e) {
                // Skip malformed migrations
                continue;
            }
        }
        
        return $requiresMySQL;
    }
}
