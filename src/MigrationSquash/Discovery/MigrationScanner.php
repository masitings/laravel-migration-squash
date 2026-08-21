<?php

namespace MigrationSquash\Discovery;

class MigrationScanner
{
    protected string $migrationPath;

    public function __construct(string $migrationPath = null)
    {
        $this->migrationPath = $migrationPath ?? database_path('migrations');
    }

    /**
     * @return array<int, array{file: string, timestamp: string, name: string}>
     */
    public function scan(): array
    {
        if (! is_dir($this->migrationPath)) {
            throw new \RuntimeException("Migration directory not found: {$this->migrationPath}");
        }

        $files = glob($this->migrationPath . '/*.php');
        
        if ($files === false) {
            return [];
        }

        $migrations = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            $name = basename($file, '.php');
            // Extract timestamp from filename (first 14 digits)
            preg_match('/(\d{14})/', $name, $matches);
            $timestamp = $matches[1] ?? '00000000000000';

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

    /**
     * Extract information about which tables are modified in each migration
     * 
     * @param array<string> $migrationFiles
     * @return array<string, array{tables: array<string, array{operation: string, type: string}>, upCode: string, downCode: string}>
     */
    public function extractTableInfo(array $migrationFiles): array
    {
        $parser = new \PhpParser\ParserFactory();
        $parser = $parser->createForNewestSupportedVersion();
        $visitor = new \PhpParser\NodeTraverser();
        $extractor = new class extends \PhpParser\NodeVisitorAbstract {
            public array $tableOperations = [];
            public ?string $currentUpMethod = null;
            public ?string $currentDownMethod = null;
            public string $upCode = '';
            public string $downCode = '';

            public function enterNode(\PhpParser\Node $node): int|null|\PhpParser\Node
            {
                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod && $node->name->name === 'up') {
                    $this->currentUpMethod = 'up';
                }
                
                if ($node instanceof \PhpParser\Node\Stmt\ClassMethod && $node->name->name === 'down') {
                    $this->currentDownMethod = 'down';
                }

                // Track Schema::create
                if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
                    $var = $node->var;
                    $name = $node->name;

                    if (
                        ($var instanceof \PhpParser\Node\Identifier && $var->name === 'Schema') ||
                        ($var instanceof \PhpParser\Node\Name && in_array((string)$var, ['Schema', '\Schema']))
                    ) {
                        if ($name instanceof \PhpParser\Node\Identifier) {
                            if ($name->name === 'create') {
                                // Get table name from first argument
                                if (isset($node->args[0]) && $node->args[0] instanceof \PhpParser\Node\Arg) {
                                    $arg = $node->args[0]->value;
                                    if ($arg instanceof \PhpParser\Node\Scalar\String_) {
                                        $this->tableOperations[$arg->value] = [
                                            'operation' => 'create',
                                            'type' => 'table',
                                        ];
                                    }
                                }
                            } elseif ($name->name === 'dropIfExists') {
                                if (isset($node->args[0]) && $node->args[0] instanceof \PhpParser\Node\Arg) {
                                    $arg = $node->args[0]->value;
                                    if ($arg instanceof \PhpParser\Node\Scalar\String_) {
                                        $this->tableOperations[$arg->value] = [
                                            'operation' => 'drop',
                                            'type' => 'table',
                                        ];
                                    }
                                }
                            } elseif ($name->name === 'table') {
                                if (isset($node->args[0]) && $node->args[0] instanceof \PhpParser\Node\Arg) {
                                    $arg = $node->args[0]->value;
                                    if ($arg instanceof \PhpParser\Node\Scalar\String_) {
                                        $this->tableOperations[$arg->value] = [
                                            'operation' => 'modify',
                                            'type' => 'table',
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                return null;
            }
        };

        $results = [];

        foreach ($migrationFiles as $file) {
            try {
                $code = file_get_contents($file);
                $stmts = $parser->parse($code);
                
                $visitor->traverse($stmts);
                
                $results[basename($file)] = [
                    'tables' => $visitor->tableOperations,
                    'upCode' => '',
                    'downCode' => '',
                ];

                // Reset visitor for next file
                $visitor->addVisitor(new class extends \PhpParser\NodeVisitorAbstract {
                    public array $tableOperations = [];
                    
                    public function enterNode(\PhpParser\Node $node): int|null|\PhpParser\Node
                    {
                        if ($node instanceof \PhpParser\Node\Stmt\ClassMethod) {
                            if ($node->name->name === 'up') {
                                // collect up code
                            } elseif ($node->name->name === 'down') {
                                // collect down code
                            }
                        }
                        
                        if (
                            $node instanceof \PhpParser\Node\Expr\StaticCall &&
                            ($node->var instanceof \PhpParser\Node\Identifier && $node->var->name === 'Schema') &&
                            $node->name instanceof \PhpParser\Node\Identifier
                        ) {
                            if ($node->name->name === 'create') {
                                if (isset($node->args[0]) && $node->args[0] instanceof \PhpParser\Node\Arg) {
                                    $arg = $node->args[0]->value;
                                    if ($arg instanceof \PhpParser\Node\Scalar\String_) {
                                        $this->tableOperations[$arg->value] = [
                                            'operation' => 'create',
                                            'type' => 'table',
                                        ];
                                    }
                                }
                            }
                        }
                        
                        return null;
                    }
                });

            } catch (\Throwable $e) {
                // Skip malformed migrations
                continue;
            }
        }

        return $results;
    }
}
