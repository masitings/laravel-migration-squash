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
     * Scan a migration file for raw SQL or data manipulation statements.
     */
    public function scanMigration(string $file): array
    {
        if (!file_exists($file)) {
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
        
        $traverser = new \PhpParser\NodeTraverser();
        $visitor = new SqlDetectorVisitor($rawSql, $dataSeeding);
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts ?? []);

        return compact('rawSql', 'dataSeeding');
    }
}

class SqlDetectorVisitor extends \PhpParser\NodeVisitorAbstract
{
    private array $rawSql = [];
    private array $dataSeeding = [];
    
    public function __construct() {}

    public function getResults(): array
    {
        return ['rawSql' => $this->rawSql, 'dataSeeding' => $this->dataSeeding];
    }
    
    public function addRawSql(string $line, string $statement): void
    {
        $this->rawSql[] = compact('line', 'statement');
    }
    
    public function addDataSeeding(int $line, string $type, string $table): void
    {
        $this->dataSeeding[] = compact('line', 'type', 'table');
    }
    
    public function enterNode(\PhpParser\Node $node): int|null|\PhpParser\Node|void
    {
        // Detect DB::statement(), DB::unprepared()
        if ($node instanceof \PhpParser\Node\Expr\StaticCall) {
            $var = $node->var;
            $name = $node->name;

            if (
                $var instanceof \PhpParser\Node\Identifier &&
                $name instanceof \PhpParser\Node\Identifier
            ) {
                if ($var->name === 'DB' && 
                    in_array($name->name, ['statement', 'unprepared'])) {
                    $this->rawSql[] = [
                        'line' => $node->getLine(),
                        'statement' => $this->printer->prettyPrint([$node]),
                    ];
                }
            }
        }

        // Detect DB::table()->insert(), update(), delete()
        if ($node instanceof \PhpParser\Node\Expr\MethodCall) {
            if ($node->var instanceof \PhpParser\Node\Expr\StaticCall) {
                $staticCall = $node->var;
                
                if (
                    $staticCall->var instanceof \PhpParser\Node\Variable &&
                    $staticCall->var->name === 'DB' &&
                    $staticCall->name instanceof \PhpParser\Node\Identifier &&
                    $staticCall->name->name === 'table' &&
                    $node->name instanceof \PhpParser\Node\Identifier &&
                    in_array($node->name->name, ['insert', 'update', 'delete'])
                ) {
                    $this->dataSeeding[] = [
                        'line' => $node->getLine(),
                        'type' => $node->name->name,
                        'table' => 'unknown',
                    ];
                }
            }
        }

        return null;
    }
}
