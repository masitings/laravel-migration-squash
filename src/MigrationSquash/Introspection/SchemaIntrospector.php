<?php

namespace MigrationSquash\Introspection;

use Illuminate\Support\Facades\DB;
use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;

class SchemaIntrospector
{
    protected string $connectionName;

    public function __construct(string $connectionName)
    {
        $this->connectionName = $connectionName;
    }

    /**
     * Introspect the entire database schema.
     *
     * @return array<string, Table> Map of table name -> Table object
     */
    public function introspect(): array
    {
        $driver = config("database.connections.{$this->connectionName}.driver");

        if ($driver === 'mysql') {
            return $this->introspectMySQL();
        }

        return $this->introspectSQLite();
    }

    /**
     * Introspect MySQL database.
     */
    protected function introspectMySQL(): array
    {
        $tables = [];
        $connection = DB::connection($this->connectionName);

        try {
            $allTables = $connection->select('SHOW TABLES');

            if (empty($allTables)) {
                return [];
            }

            $firstKey = array_key_first((array) reset($allTables));
            $tableNames = array_column(
                array_map(fn ($t) => (array) $t, $allTables),
                $firstKey
            );

            foreach ($tableNames as $tableName) {
                if ($tableName === 'migrations') {
                    continue;
                }

                $columns = $this->getMySQLColumns($tableName);
                $indexes = $this->getMySQLIndexes($tableName);
                $foreignKeys = $this->getMySQLForeignKeys($tableName);

                $tables[$tableName] = Table::fromDb(
                    $tableName,
                    $columns,
                    $indexes,
                    $foreignKeys,
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to introspect MySQL schema: {$e->getMessage()}", 0, $e);
        }

        return $tables;
    }

    /**
     * Introspect SQLite database.
     */
    protected function introspectSQLite(): array
    {
        $tables = [];
        $connection = DB::connection($this->connectionName);

        try {
            $results = $connection->select(
                "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
            $tableNames = array_column(
                array_map(fn ($r) => (array) $r, $results),
                'name'
            );

            foreach ($tableNames as $tableName) {
                if ($tableName === 'migrations') {
                    continue;
                }

                $columns = $this->getSQLiteColumns($tableName);
                $indexes = $this->getSQLiteIndexes($tableName);
                $foreignKeys = $this->getSQLiteForeignKeys($tableName);

                $tables[$tableName] = Table::fromDb(
                    $tableName,
                    $columns,
                    $indexes,
                    $foreignKeys,
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to introspect SQLite schema: {$e->getMessage()}", 0, $e);
        }

        return $tables;
    }

    /**
     * Get columns for a MySQL table with normalized output.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed, length: ?int, unsigned: bool, collation: ?string, auto_increment: bool}>
     */
    protected function getMySQLColumns(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);
        $columns = $connection->select("DESCRIBE `{$tableName}`");

        return array_map(function ($col) {
            $rawType = $col->Type;
            $unsigned = str_contains($rawType, 'unsigned');
            $baseType = $this->normalizeType(preg_replace('/\s*unsigned$/i', '', $rawType));
            $length = null;

            // enum('a','b') / set('a','b') carry their values in the raw type
            // and must be captured before the length regex sees the parens.
            $allowedValues = $this->parseAllowedValues($rawType);

            if ($allowedValues === [] && preg_match('/\((\d+)\)/', $rawType, $m)) {
                $length = (int) $m[1];
            }

            $default = $col->Default;

            // MySQL renders boolean as tinyint(1). Without this the generator
            // emits tinyInteger(), which comes back as tinyint(4) and fails
            // verification on a length mismatch.
            if ($baseType === 'tinyint' && $length === 1) {
                $baseType = 'boolean';
                $length = null;

                if ($default === '0' || $default === 0) {
                    $default = false;
                } elseif ($default === '1' || $default === 1) {
                    $default = true;
                }
            }

            return [
                'name' => $col->Field,
                'type' => $baseType,
                'nullable' => $col->Null === 'YES',
                'default' => $default,
                'length' => $length,
                'unsigned' => $unsigned,
                'collation' => null,
                'auto_increment' => str_contains($col->Extra ?? '', 'auto_increment'),
                'allowed_values' => $allowedValues,
            ];
        }, $columns);
    }

    /**
     * Get indexes for a MySQL table with normalized output.
     *
     * @return array<int, array{key_name: string, index_type: string, columns: array<string>}>
     */
    protected function getMySQLIndexes(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);
        $indexes = $connection->select("SHOW INDEXES FROM `{$tableName}`");

        $grouped = [];

        foreach ($indexes as $idx) {
            $groupName = $idx->Key_name;

            if (! isset($grouped[$groupName])) {
                $type = 'index';

                if ($groupName === 'PRIMARY') {
                    $type = 'primary';
                } elseif (! $idx->Non_unique) {
                    $type = 'unique';
                } elseif (strtolower($idx->Index_type) === 'fulltext') {
                    $type = 'fulltext';
                }

                $grouped[$groupName] = [
                    'index_type' => $type,
                    'columns' => [],
                ];
            }

            $grouped[$groupName]['columns'][] = $idx->Column_name;
        }

        return array_map(function ($data, $name) {
            return [
                'key_name' => $name,
                'index_type' => $data['index_type'],
                'columns' => $data['columns'],
            ];
        }, $grouped, array_keys($grouped));
    }

    /**
     * Get foreign keys for a MySQL table using parameter binding.
     *
     * @return array<int, array{name: string, column: string, referenced_table: string, referenced_column: string, on_update: ?string, on_delete: ?string}>
     */
    protected function getMySQLForeignKeys(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $fks = $connection->select('
            SELECT
                kcu.CONSTRAINT_NAME as constraint_name,
                kcu.COLUMN_NAME as column_name,
                kcu.REFERENCED_TABLE_NAME as referenced_table_name,
                kcu.REFERENCED_COLUMN_NAME as referenced_column_name,
                rc.UPDATE_RULE as on_update,
                rc.DELETE_RULE as on_delete
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
            WHERE kcu.TABLE_SCHEMA = DATABASE()
                AND kcu.TABLE_NAME = ?
                AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ', [$tableName]);

        return array_map(function ($fk) {
            return [
                'name' => $fk->constraint_name,
                'column' => $fk->column_name,
                'referenced_table' => $fk->referenced_table_name,
                'referenced_column' => $fk->referenced_column_name,
                'on_update' => $fk->on_update,
                'on_delete' => $fk->on_delete,
            ];
        }, $fks);
    }

    /**
     * Get columns for a SQLite table with normalized output.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed, length: ?int, unsigned: bool, collation: ?string, auto_increment: bool}>
     */
    protected function getSQLiteColumns(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);
        $pragma = $connection->select("PRAGMA table_info(`{$tableName}`)");

        // Check if table has autoincrement via sqlite_master
        $createSql = $connection->selectOne(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name=?",
            [$tableName]
        );
        $hasAutoIncrement = $createSql && str_contains(
            strtoupper($createSql->sql ?? ''),
            'AUTOINCREMENT'
        );

        // Laravel renders enum() on SQLite as a varchar plus a CHECK IN (...)
        // constraint, so the allowed values only survive in the CREATE TABLE
        // statement. Without this the values are lost on the round trip.
        $enumValues = $this->parseSqliteCheckConstraints($createSql->sql ?? '');

        return array_map(function ($col) use ($hasAutoIncrement, $enumValues) {
            $rawType = strtolower($col->type ?? 'text');
            $unsigned = str_contains($rawType, 'unsigned');
            $baseType = $this->normalizeType(preg_replace('/\s*unsigned$/i', '', $rawType));
            $length = null;

            if (preg_match('/\((\d+)\)/', $rawType, $m)) {
                $length = (int) $m[1];
            }

            // In SQLite, boolean is stored as tinyint(1)
            if ($baseType === 'tinyint' && $length === 1) {
                $baseType = 'boolean';
                $length = null;
            }

            $default = $col->dflt_value;
            if ($default !== null) {
                if (is_string($default) && str_starts_with($default, "'") && str_ends_with($default, "'")) {
                    $default = substr($default, 1, -1);
                }
                if ($default === 'NULL') {
                    $default = null;
                } elseif ($baseType === 'boolean') {
                    if ($default === '0' || $default === 0) {
                        $default = false;
                    } elseif ($default === '1' || $default === 1) {
                        $default = true;
                    }
                }
            }

            $isAutoIncrement = $col->pk && $hasAutoIncrement;

            $allowedValues = $enumValues[$col->name] ?? [];

            if ($allowedValues !== []) {
                $baseType = 'enum';
                $length = null;
            }

            return [
                'name' => $col->name,
                'type' => $baseType,
                'nullable' => ! (bool) $col->notnull,
                'default' => $default,
                'length' => $length,
                'unsigned' => $unsigned,
                'collation' => null,
                'auto_increment' => $isAutoIncrement,
                'allowed_values' => $allowedValues,
            ];
        }, $pragma);
    }

    /**
     * Get indexes for a SQLite table with normalized output.
     *
     * @return array<int, array{key_name: string, index_type: string, columns: array<string>}>
     */
    protected function getSQLiteIndexes(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);
        $indexList = $connection->select("PRAGMA index_list(`{$tableName}`)");

        $indexes = [];

        foreach ($indexList as $idx) {
            $indexName = $idx->name;
            $isUnique = (bool) $idx->unique;

            // Get columns for this index
            $indexInfo = $connection->select("PRAGMA index_info(`{$indexName}`)");
            $columns = array_map(fn ($info) => $info->name, $indexInfo);

            if (empty($columns)) {
                continue;
            }

            $type = $isUnique ? 'unique' : 'index';

            // Check if this is the primary key index
            if ($idx->origin === 'pk') {
                $type = 'primary';
            }

            $indexes[] = [
                'key_name' => $indexName,
                'index_type' => $type,
                'columns' => $columns,
            ];
        }

        return $indexes;
    }

    /**
     * Get foreign keys for a SQLite table via PRAGMA foreign_key_list.
     *
     * @return array<int, array{name: string, column: string, referenced_table: string, referenced_column: string, on_update: ?string, on_delete: ?string}>
     */
    protected function getSQLiteForeignKeys(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);
        $fkList = $connection->select("PRAGMA foreign_key_list(`{$tableName}`)");

        return array_map(function ($fk) use ($tableName) {
            return [
                'name' => "{$tableName}_{$fk->from}_foreign",
                'column' => $fk->from,
                'referenced_table' => $fk->table,
                'referenced_column' => $fk->to,
                'on_update' => $fk->on_update !== 'NO ACTION' ? $fk->on_update : null,
                'on_delete' => $fk->on_delete !== 'NO ACTION' ? $fk->on_delete : null,
            ];
        }, $fkList);
    }

    /**
     * Parse the allowed values out of a MySQL enum(...) or set(...) raw type.
     *
     * @return array<int, string> Empty when the type is not an enum or set
     */
    protected function parseAllowedValues(string $rawType): array
    {
        if (! preg_match('/^(?:enum|set)\((.*)\)$/is', trim($rawType), $m)) {
            return [];
        }

        // Values are single-quoted and comma separated; '' is an escaped quote.
        preg_match_all("/'((?:[^']|'')*)'/", $m[1], $values);

        return array_map(
            fn (string $v) => str_replace("''", "'", $v),
            $values[1] ?? [],
        );
    }

    /**
     * Parse column CHECK (... in (...)) constraints out of a SQLite
     * CREATE TABLE statement, which is how Laravel renders enum columns.
     *
     * @return array<string, array<int, string>> Map of column name -> allowed values
     */
    protected function parseSqliteCheckConstraints(string $createSql): array
    {
        if ($createSql === '') {
            return [];
        }

        $result = [];

        // check ("status" in ('draft', 'published'))
        $pattern = '/check\s*\(\s*["`\[]?(\w+)["`\]]?\s+in\s*\(([^)]*)\)\s*\)/i';

        if (! preg_match_all($pattern, $createSql, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $column = $match[1];

            preg_match_all("/'((?:[^']|'')*)'/", $match[2], $values);

            $parsed = array_map(
                fn (string $v) => str_replace("''", "'", $v),
                $values[1] ?? [],
            );

            if ($parsed !== []) {
                $result[$column] = $parsed;
            }
        }

        return $result;
    }

    /**
     * Normalize a raw database type to a canonical form.
     *
     * Strips parenthesized length/precision so types like 'bigint(20)' and
     * 'INTEGER' become comparable.
     */
    protected function normalizeType(string $rawType): string
    {
        $type = strtolower(trim($rawType));

        // enum(...) and set(...) hold quoted strings, not a numeric length, so
        // the length regex below leaves them untouched and the whole value
        // would leak through as the "type". Collapse them first.
        if (preg_match('/^(enum|set)\s*\(/', $type, $m)) {
            return $m[1];
        }

        // Strip length/precision: bigint(20) -> bigint, varchar(255) -> varchar
        $type = preg_replace('/\(\d+(?:,\s*\d+)?\)/', '', $type);
        $type = trim($type);

        // Canonical mapping for cross-driver comparisons
        $map = [
            'int' => 'integer',
            'int4' => 'integer',
            'int8' => 'bigint',
            'smallint' => 'smallint',
            'tinyint' => 'tinyint',
            'mediumint' => 'mediumint',
            'bigint' => 'bigint',
            'integer' => 'integer',
            'real' => 'float',
            'float' => 'float',
            'double' => 'double',
            'double precision' => 'double',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'varchar' => 'varchar',
            'char' => 'char',
            'text' => 'text',
            'mediumtext' => 'mediumtext',
            'longtext' => 'longtext',
            'tinytext' => 'tinytext',
            'blob' => 'blob',
            'mediumblob' => 'mediumblob',
            'longblob' => 'longblob',
            'date' => 'date',
            'datetime' => 'datetime',
            'timestamp' => 'timestamp',
            'time' => 'time',
            'year' => 'year',
            'json' => 'json',
            'enum' => 'enum',
            'set' => 'set',
            'binary' => 'binary',
            'varbinary' => 'varbinary',
        ];

        return $map[$type] ?? $type;
    }

    /**
     * Get a snapshot of the current schema state for comparison.
     *
     * @return array{tables: array<string, array<string, mixed>>}
     */
    public function getSnapshot(): array
    {
        $tables = $this->introspect();

        return [
            'tables' => array_map(fn (Table $table) => $this->serializeTable($table), $tables),
        ];
    }

    /**
     * Serialize a Table object for comparison.
     */
    protected function serializeTable(Table $table): array
    {
        return [
            'name' => $table->name,
            'columns' => array_map(fn (Column $col) => [
                'name' => $col->name,
                'type' => $col->type,
                'nullable' => $col->nullable,
                'default' => $col->default,
                'length' => $col->length,
                'unsigned' => $col->unsigned,
                'collation' => $col->collation,
                'auto_increment' => $col->autoIncrement,
                'allowed_values' => $col->allowedValues,
            ], $table->columns),
            'indexes' => array_map(fn (Index $idx) => [
                'name' => $idx->name,
                'type' => $idx->type,
                'columns' => $idx->columns,
                'options' => $idx->options,
            ], $table->indexes),
            'foreign_keys' => array_map(fn (ForeignKey $fk) => [
                'name' => $fk->name,
                'column' => $fk->column,
                'referenced_table' => $fk->referencedTable,
                'referenced_column' => $fk->referencedColumn,
                'on_update' => $fk->onUpdate,
                'on_delete' => $fk->onDelete,
            ], $table->foreignKeys),
        ];
    }
}
