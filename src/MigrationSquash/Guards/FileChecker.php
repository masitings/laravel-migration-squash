<?php

namespace MigrationSquash\Guards;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

class FileChecker
{
    protected Parser $parser;

    protected Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->printer = new Standard;
    }

    /**
     * Scan a migration file for raw SQL or data manipulation statements.
     *
     * @return array{rawSql: array<int, array{line: int, statement: string}>, dataSeeding: array<int, array{line: int, type: string, table: string}>}
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

        $traverser = new NodeTraverser;
        $visitor = new SqlDetectorVisitor($this->printer);
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts ?? []);

        return $visitor->getResults();
    }
}

/**
 * AST visitor that detects raw SQL statements and data seeding operations
 * in migration files.
 */
class SqlDetectorVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array{line: int, statement: string}> */
    private array $rawSql = [];

    /** @var array<int, array{line: int, type: string, table: string}> */
    private array $dataSeeding = [];

    private Standard $printer;

    public function __construct(Standard $printer)
    {
        $this->printer = $printer;
    }

    /**
     * @return array{rawSql: array<int, array{line: int, statement: string}>, dataSeeding: array<int, array{line: int, type: string, table: string}>}
     */
    public function getResults(): array
    {
        return [
            'rawSql' => $this->rawSql,
            'dataSeeding' => $this->dataSeeding,
        ];
    }

    public function enterNode(Node $node): int|null|Node
    {
        // Detect DB::statement(), DB::unprepared()
        if ($node instanceof Node\Expr\StaticCall) {
            $this->detectRawSql($node);
        }

        // Detect DB::table('x')->insert(), ->update(), ->delete()
        if ($node instanceof Node\Expr\MethodCall) {
            $this->detectDataSeeding($node);
        }

        return null;
    }

    /**
     * Detect DB::statement() and DB::unprepared() calls.
     */
    private function detectRawSql(Node\Expr\StaticCall $node): void
    {
        // StaticCall uses $node->class, not $node->var
        $class = $node->class;
        $name = $node->name;

        if (! $name instanceof Node\Identifier) {
            return;
        }

        $isDbCall = false;

        if ($class instanceof Node\Name) {
            $className = $class->toString();
            $isDbCall = in_array($className, ['DB', '\DB', 'Illuminate\Support\Facades\DB', '\Illuminate\Support\Facades\DB']);
        }

        if (! $isDbCall) {
            return;
        }

        // Only statement() and unprepared() actually execute unreproducible DDL.
        // DB::raw() is routine inside legitimate schema migrations
        // (e.g. ->default(DB::raw('CURRENT_TIMESTAMP'))) and DB::select() is a
        // read. Blocking either excludes squashable migrations from the sandbox
        // run, which silently shrinks the schema being verified.
        if (in_array($name->name, ['statement', 'unprepared'])) {
            $this->rawSql[] = [
                'line' => $node->getLine(),
                'statement' => $this->printer->prettyPrint([$node]),
            ];
        }
    }

    /**
     * Detect DB::table('x')->insert(), ->update(), ->delete() calls.
     * Extracts actual table name from the DB::table() argument.
     */
    private function detectDataSeeding(Node\Expr\MethodCall $node): void
    {
        $methodName = $node->name;

        if (! $methodName instanceof Node\Identifier) {
            return;
        }

        if (! in_array($methodName->name, ['insert', 'update', 'delete'])) {
            return;
        }

        // Walk up to find DB::table('x') pattern
        $var = $node->var;

        // Could be chained: DB::table('x')->where(...)->insert(...)
        while ($var instanceof Node\Expr\MethodCall) {
            $var = $var->var;
        }

        if (! $var instanceof Node\Expr\StaticCall) {
            return;
        }

        $class = $var->class;
        $staticMethodName = $var->name;

        $isDbCall = false;

        if ($class instanceof Node\Name) {
            $className = $class->toString();
            $isDbCall = in_array($className, ['DB', '\DB', 'Illuminate\Support\Facades\DB', '\Illuminate\Support\Facades\DB']);
        }

        if (! $isDbCall) {
            return;
        }

        if (! $staticMethodName instanceof Node\Identifier || $staticMethodName->name !== 'table') {
            return;
        }

        // Extract the table name from DB::table('table_name')
        $tableName = 'unknown';

        if (isset($var->args[0]) && $var->args[0] instanceof Node\Arg) {
            $arg = $var->args[0]->value;

            if ($arg instanceof Node\Scalar\String_) {
                $tableName = $arg->value;
            }
        }

        $this->dataSeeding[] = [
            'line' => $node->getLine(),
            'type' => $methodName->name,
            'table' => $tableName,
        ];
    }
}
