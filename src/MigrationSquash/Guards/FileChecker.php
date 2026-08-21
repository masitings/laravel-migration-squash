<?php

namespace MigrationSquash\Guards;

use PhpParser\Error as ParserError;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class FileChecker
{
    protected \PhpParser\Parser $parser;
    protected Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->printer = new Standard();
    }

    /**
     * Scan a migration file and detect raw SQL or data manipulation statements.
     *
     * @param  string  $file  Path to the migration file
     * @return array{
     *     rawSql: array<int, array{line: int, statement: string}>,
     *     dataSeeding: array<int, array{line: int, type: string, table: string}>
     * }
     */
    public function scanMigration(string $file): array
    {
        if (! file_exists($file)) {
            throw new \RuntimeException("Migration file not found: {$file}");
        }

        $code = file_get_contents($file);
        
        try {
            $stmts = $this->parser->parse($code);
        } catch (ParserError $e) {
            throw new \RuntimeException("Failed to parse migration file: {$e->getMessage()}");
        }

        $rawSql = [];
        $dataSeeding = [];

        // Create visitor to scan migration code
        $visitor = new \PhpParser\NodeTraverser();
        
        // Define visitor class separately (inline anonymous class has issues)
        $scannerVisitor = new class($rawSql, $dataSeeding) extends \PhpParser\NodeVisitor {
            private array $rawSql;
            private array $dataSeeding;

            public function __construct(array &$rawSql, array &$dataSeeding)
            {
                $this->rawSql = &$rawSql;
                $this->dataSeeding = &$dataSeeding;
            }

            public function enterNode(\PhpParser\Node $node): \PhpParser\Node|void
            {
                // Detect DB::statement(), DB::unprepared()
                if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
                    $var = $node->var;
                    $name = $node->name;

                    if (
                        $var instanceof \PhpParser\Node\Variable &&
                        $name instanceof \PhpParser\Node\Identifier
                    ) {
                        if ($name->name === 'statement' || $name->name === 'unprepared') {
                            // Check if it's called via DB facade or Facade
                            if (
                                $var->name === 'DB' ||
                                ($var instanceof \PhpParser\Node\PropertyFetch && 
                                 $var->name === 'DB')
                            ) {
                                $this->rawSql[] = [
                                    'line' => $node->getLine(),
                                    'statement' => $this->printer->prettyPrint([$node]),
                                ];
                            }
                        }

                        // Detect DB::table()->insert(), update(), delete()
                        if ($name->name === 'table') {
                            $nestedVisitor = new class extends \PhpParser\NodeVisitor {
                                private array $dataSeeding;
                                private ?string $tableName = null;

                                public function __construct(array &$dataSeeding)
                                {
                                    $this->dataSeeding =& $dataSeeding;
                                }

                                public function enterNode(\PhpParser\Node $node): void
                                {
                                    if ($node instanceof \PhpParser\Node\Expr\MethodCall &&
                                        $node->var instanceof \PhpParser\Node\Expr\StaticCall) {
                                        $staticCall = $node->var;
                                        
                                        if (
                                            $staticCall->var instanceof \PhpParser\Node\Variable &&
                                            $staticCall->var->name === 'DB' &&
                                            $staticCall->name instanceof \PhpParser\Node\Identifier &&
                                            $staticCall->name->name === 'table'
                                        ) {
                                            // Get table name from first argument
                                            if (
                                                isset($staticCall->args[0]) &&
                                                $staticCall->args[0] instanceof \PhpParser\Node\Arg
                                            ) {
                                                $arg = $staticCall->args[0]->value;
                                                if ($arg instanceof \PhpParser\Node\Scalar\String_) {
                                                    $this->tableName = $arg->value;
                                                }
                                            }
                                        }
                                    }

                                    if ($node instanceof \PhpParser\Node\Expr\MethodCall &&
                                        $this->tableName !== null) {
                                        if (in_array($node->name->name, ['insert', 'update', 'delete'])) {
                                            $this->dataSeeding[] = [
                                                'line' => $node->getLine(),
                                                'type' => $node->name->name,
                                                'table' => $this->tableName,
                                            ];
                                        }
                                    }
                                }

                                public function leaveNode(\PhpParser\Node $node): void
                                {
                                    if ($node instanceof \PhpParser\Node\Stmt) {
                                        $this->tableName = null;
                                    }
                                }
                            };
                            
                            $nestedVisitor->enterNode($node);
                        }
                    }
                }
            }
        };

        $visitor->traverse($stmts ?? []);

        return compact('rawSql', 'dataSeeding');
    }
}
