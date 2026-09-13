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

        return match ($driver) {
            'mysql' => $this->introspectMySQL(),
            'sqlite' => $this->introspectSQLite(),
            'pgsql' => $this->introspectPostgres(),
            'sqlsrv' => $this->introspectSqlServer(),
            default => throw new \RuntimeException("Unsupported driver '{$driver}' for schema introspection."),
        };
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

        // SHOW FULL COLUMNS rather than DESCRIBE: it is the only form that
        // returns Collation and Comment, both of which are part of the schema
        // and were previously never captured or compared.
        $columns = $connection->select("SHOW FULL COLUMNS FROM `{$tableName}`");

        $defaultCollation = $this->mysqlDefaultCollation();

        return array_map(function ($col) use ($defaultCollation) {
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
                // Only record a collation that differs from the database
                // default. Recording the default on every column would make
                // the generated migrations noisy without changing the schema.
                'collation' => ($col->Collation ?? null) !== null && $col->Collation !== $defaultCollation
                    ? $col->Collation
                    : null,
                'comment' => ($col->Comment ?? '') !== '' ? $col->Comment : null,
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
                // MySQL reports RESTRICT when no rule was specified, and
                // RESTRICT is the default behaviour anyway. Recording it would
                // put a redundant ->onUpdate('RESTRICT') on every foreign key
                // in the generated migrations.
                'on_update' => $this->normalizeReferentialRule($fk->on_update),
                'on_delete' => $this->normalizeReferentialRule($fk->on_delete),
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
        $collations = $this->parseSqliteCollations($createSql->sql ?? '');

        return array_map(function ($col) use ($hasAutoIncrement, $enumValues, $collations) {
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
                'collation' => $collations[$col->name] ?? null,
                // SQLite has no column comments.
                'comment' => null,
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
                'on_update' => $this->normalizeReferentialRule($fk->on_update),
                'on_delete' => $this->normalizeReferentialRule($fk->on_delete),
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
     * The database's default collation, used to decide which column
     * collations are worth recording.
     */
    protected function mysqlDefaultCollation(): ?string
    {
        try {
            $row = DB::connection($this->connectionName)->selectOne('SELECT @@collation_database AS c');

            return $row->c ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse per-column COLLATE clauses out of a SQLite CREATE TABLE statement.
     *
     * PRAGMA table_info does not report collation, so without this a column
     * declared COLLATE NOCASE would compare equal to one that is not.
     *
     * @return array<string, string> Map of column name -> collation
     */
    protected function parseSqliteCollations(string $createSql): array
    {
        if ($createSql === '') {
            return [];
        }

        $result = [];
        // Laravel renders SQLite collations as: "col" varchar not null collate 'NOCASE'
        // The collation name can be single-quoted, double-quoted, backticked,
        // bracketed or bare, so accept all of them.
        $pattern = '/["`\[]?(\w+)["`\]]?[^,()]*?\bcollate\s+[\'"`\[]?(\w+)[\'"`\]]?/i';

        if (! preg_match_all($pattern, $createSql, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $result[$match[1]] = $match[2];
        }

        return $result;
    }

    /**
     * Normalize a foreign key referential action.
     *
     * Both "NO ACTION" and "RESTRICT" mean "no rule was specified", and they
     * behave identically. Collapsing them to null keeps the comparison stable
     * across drivers and keeps the generated migrations free of redundant
     * ->onDelete('RESTRICT') calls.
     */
    protected function normalizeReferentialRule(?string $rule): ?string
    {
        if ($rule === null) {
            return null;
        }

        return in_array(strtoupper($rule), ['NO ACTION', 'RESTRICT'], true) ? null : strtoupper($rule);
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

        // Strip length/precision: bigint(20) -> bigint, varchar(255) -> varchar, nvarchar(max) -> nvarchar
        $type = preg_replace('/\((?:\d+(?:,\s*\d+)?|max)\)/i', '', $type);
        $type = trim($type);

        // Canonical mapping for cross-driver comparisons
        $map = [
            'int' => 'integer',
            'int2' => 'smallint',
            'int4' => 'integer',
            'int8' => 'bigint',
            'smallint' => 'smallint',
            'tinyint' => 'tinyint',
            'mediumint' => 'mediumint',
            'bigint' => 'bigint',
            'integer' => 'integer',
            'real' => 'float',
            'float' => 'float',
            'float4' => 'float',
            'float8' => 'double',
            'double' => 'double',
            'double precision' => 'double',
            'decimal' => 'decimal',
            'numeric' => 'decimal',
            'money' => 'decimal',
            'smallmoney' => 'decimal',
            'boolean' => 'boolean',
            'bool' => 'boolean',
            'bit' => 'boolean',
            'varchar' => 'varchar',
            'character varying' => 'varchar',
            'nvarchar' => 'varchar',
            'char' => 'char',
            'character' => 'char',
            'nchar' => 'char',
            'text' => 'text',
            'ntext' => 'text',
            'mediumtext' => 'mediumtext',
            'longtext' => 'longtext',
            'tinytext' => 'tinytext',
            'blob' => 'blob',
            'image' => 'blob',
            'bytea' => 'blob',
            'mediumblob' => 'mediumblob',
            'longblob' => 'longblob',
            'date' => 'date',
            'datetime' => 'datetime',
            'datetime2' => 'datetime',
            'smalldatetime' => 'datetime',
            'timestamp' => 'timestamp',
            'timestamptz' => 'timestamp',
            'timestamp without time zone' => 'timestamp',
            'timestamp with time zone' => 'timestamp',
            'datetimeoffset' => 'timestamp',
            'time' => 'time',
            'year' => 'year',
            'json' => 'json',
            'jsonb' => 'json',
            'uuid' => 'uuid',
            'uniqueidentifier' => 'uuid',
            'serial' => 'integer',
            'bigserial' => 'bigint',
            'smallserial' => 'smallint',
            'enum' => 'enum',
            'set' => 'set',
            'binary' => 'binary',
            'varbinary' => 'binary',
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
     * Introspect PostgreSQL database.
     */
    protected function introspectPostgres(): array
    {
        $tables = [];
        $connection = DB::connection($this->connectionName);

        try {
            $results = $connection->select("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = current_schema()
                  AND table_type = 'BASE TABLE'
                ORDER BY table_name
            ");

            $tableNames = array_column(
                array_map(fn ($r) => (array) $r, $results),
                'table_name'
            );

            foreach ($tableNames as $tableName) {
                if ($tableName === 'migrations') {
                    continue;
                }

                $columns = $this->getPostgresColumns($tableName);
                $indexes = $this->getPostgresIndexes($tableName);
                $foreignKeys = $this->getPostgresForeignKeys($tableName);

                $tables[$tableName] = Table::fromDb(
                    $tableName,
                    $columns,
                    $indexes,
                    $foreignKeys,
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to introspect PostgreSQL schema: {$e->getMessage()}", 0, $e);
        }

        return $tables;
    }

    /**
     * Get columns for a PostgreSQL table with normalized output.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed, length: ?int, unsigned: bool, collation: ?string, comment: ?string, auto_increment: bool, allowed_values: array<string>}>
     */
    protected function getPostgresColumns(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $columns = $connection->select('
            SELECT
                c.column_name,
                c.data_type,
                c.udt_name,
                c.is_nullable,
                c.column_default,
                c.character_maximum_length,
                c.numeric_precision,
                c.collation_name,
                c.is_identity,
                pgd.description as column_comment
            FROM information_schema.columns c
            LEFT JOIN pg_catalog.pg_statio_all_tables st
                ON c.table_schema = st.schemaname AND c.table_name = st.relname
            LEFT JOIN pg_catalog.pg_description pgd
                ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
            WHERE c.table_schema = current_schema()
              AND c.table_name = ?
            ORDER BY c.ordinal_position
        ', [$tableName]);

        $defaultCollation = $this->postgresDefaultCollation();

        return array_map(function ($col) use ($defaultCollation) {
            $dataType = strtolower($col->column_name ?? '');
            $rawDataType = strtolower($col->data_type ?? '');
            $udtName = strtolower($col->udt_name ?? '');
            $rawType = $rawDataType === 'user-defined' ? $udtName : $rawDataType;

            $allowedValues = [];
            if ($rawDataType === 'user-defined' || $rawDataType === 'enum') {
                $allowedValues = $this->getPostgresEnumValues($col->udt_name);
            }

            $baseType = $allowedValues !== []
                ? 'enum'
                : $this->normalizeType($rawType);

            $autoIncrement = ($col->is_identity ?? 'NO') === 'YES';
            $defaultRaw = $col->column_default;
            $default = $this->cleanPostgresDefault($defaultRaw, $baseType, $autoIncrement);

            if (in_array($rawType, ['serial', 'bigserial', 'smallserial'], true)) {
                $autoIncrement = true;
            }

            $length = null;
            if (in_array($baseType, ['varchar', 'char'], true) && $col->character_maximum_length !== null) {
                $length = (int) $col->character_maximum_length;
            }

            return [
                'name' => $col->column_name,
                'type' => $baseType,
                'nullable' => $col->is_nullable === 'YES',
                'default' => $default,
                'length' => $length,
                'unsigned' => false,
                'collation' => ($col->collation_name ?? null) !== null && $col->collation_name !== $defaultCollation
                    ? $col->collation_name
                    : null,
                'comment' => ($col->column_comment ?? '') !== '' ? $col->column_comment : null,
                'auto_increment' => $autoIncrement,
                'allowed_values' => $allowedValues,
            ];
        }, $columns);
    }

    /**
     * Get indexes for a PostgreSQL table.
     *
     * @return array<int, array{key_name: string, index_type: string, columns: array<string>}>
     */
    protected function getPostgresIndexes(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $indexes = $connection->select('
            SELECT
                c2.relname AS index_name,
                i.indisunique AS is_unique,
                i.indisprimary AS is_primary,
                am.amname AS am_name,
                a.attname AS column_name
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_index i ON i.oid = c.indrelid
            JOIN pg_class c2 ON c2.oid = i.indexrelid
            JOIN pg_am am ON am.oid = c2.relam
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = ANY(i.indkey)
            WHERE n.nspname = current_schema()
              AND c.relname = ?
            ORDER BY c2.relname, a.attnum
        ', [$tableName]);

        $grouped = [];
        foreach ($indexes as $idx) {
            $name = $idx->index_name;

            if (! isset($grouped[$name])) {
                $type = 'index';
                if ($idx->is_primary) {
                    $type = 'primary';
                } elseif ($idx->is_unique) {
                    $type = 'unique';
                }

                $grouped[$name] = [
                    'index_type' => $type,
                    'columns' => [],
                ];
            }

            $grouped[$name]['columns'][] = $idx->column_name;
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
     * Get foreign keys for a PostgreSQL table.
     *
     * @return array<int, array{name: string, column: string, referenced_table: string, referenced_column: string, on_update: ?string, on_delete: ?string}>
     */
    protected function getPostgresForeignKeys(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $fks = $connection->select("
            SELECT
                tc.constraint_name,
                kcu.column_name,
                ccu.table_name AS referenced_table,
                ccu.column_name AS referenced_column,
                rc.update_rule AS on_update,
                rc.delete_rule AS on_delete
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.referential_constraints rc
              ON tc.constraint_name = rc.constraint_name
             AND tc.table_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu
              ON rc.unique_constraint_name = ccu.constraint_name
             AND rc.unique_constraint_schema = ccu.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = current_schema()
              AND tc.table_name = ?
        ", [$tableName]);

        return array_map(function ($fk) {
            return [
                'name' => $fk->constraint_name,
                'column' => $fk->column_name,
                'referenced_table' => $fk->referenced_table,
                'referenced_column' => $fk->referenced_column,
                'on_update' => $this->normalizeReferentialRule($fk->on_update),
                'on_delete' => $this->normalizeReferentialRule($fk->on_delete),
            ];
        }, $fks);
    }

    /**
     * Get database-level default collation for PostgreSQL.
     */
    protected function postgresDefaultCollation(): ?string
    {
        try {
            $result = DB::connection($this->connectionName)->selectOne(
                'SELECT datcollate FROM pg_database WHERE datname = current_database()'
            );

            return $result->datcollate ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get allowed enum values for a PostgreSQL custom enum type.
     *
     * @return array<string>
     */
    protected function getPostgresEnumValues(string $udtName): array
    {
        try {
            $rows = DB::connection($this->connectionName)->select('
                SELECT e.enumlabel
                FROM pg_type t
                JOIN pg_enum e ON t.oid = e.enumtypid
                JOIN pg_namespace n ON n.oid = t.typnamespace
                WHERE t.typname = ?
                ORDER BY e.enumsortorder
            ', [$udtName]);

            return array_column(array_map(fn ($r) => (array) $r, $rows), 'enumlabel');
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Clean default value string for PostgreSQL.
     */
    protected function cleanPostgresDefault(?string $default, string &$baseType, bool &$autoIncrement): mixed
    {
        if ($default === null) {
            return null;
        }

        if (str_contains($default, 'nextval(')) {
            $autoIncrement = true;

            return null;
        }

        $cleaned = preg_replace('/::[a-zA-Z0-9_\s"\[\]]+$/', '', $default);

        if (strtolower($cleaned) === 'null') {
            return null;
        }

        if ($baseType === 'boolean') {
            if (in_array(strtolower($cleaned), ['true', '1', "'true'"], true)) {
                return true;
            }
            if (in_array(strtolower($cleaned), ['false', '0', "'false'"], true)) {
                return false;
            }
        }

        if (str_starts_with($cleaned, "'") && str_ends_with($cleaned, "'")) {
            return substr($cleaned, 1, -1);
        }

        return $cleaned;
    }

    /**
     * Introspect SQL Server database.
     */
    protected function introspectSqlServer(): array
    {
        $tables = [];
        $connection = DB::connection($this->connectionName);

        try {
            $results = $connection->select("
                SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_TYPE = 'BASE TABLE'
                  AND TABLE_NAME != 'migrations'
                  AND TABLE_NAME != 'sysdiagrams'
                ORDER BY TABLE_NAME
            ");

            $tableNames = array_column(
                array_map(fn ($r) => (array) $r, $results),
                'TABLE_NAME'
            );

            foreach ($tableNames as $tableName) {
                $columns = $this->getSqlServerColumns($tableName);
                $indexes = $this->getSqlServerIndexes($tableName);
                $foreignKeys = $this->getSqlServerForeignKeys($tableName);

                $tables[$tableName] = Table::fromDb(
                    $tableName,
                    $columns,
                    $indexes,
                    $foreignKeys,
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to introspect SQL Server schema: {$e->getMessage()}", 0, $e);
        }

        return $tables;
    }

    /**
     * Get columns for a SQL Server table.
     *
     * @return array<int, array{name: string, type: string, nullable: bool, default: mixed, length: ?int, unsigned: bool, collation: ?string, comment: ?string, auto_increment: bool, allowed_values: array<string>}>
     */
    protected function getSqlServerColumns(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $columns = $connection->select("
            SELECT
                c.COLUMN_NAME AS name,
                c.DATA_TYPE AS data_type,
                c.IS_NULLABLE AS is_nullable,
                c.COLUMN_DEFAULT AS column_default,
                c.CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
                c.COLLATION_NAME AS collation_name,
                sc.is_identity AS is_identity,
                ep.value AS comment
            FROM INFORMATION_SCHEMA.COLUMNS c
            JOIN sys.objects o ON o.name = c.TABLE_NAME AND o.type = 'U'
            JOIN sys.columns sc ON sc.object_id = o.object_id AND sc.name = c.COLUMN_NAME
            LEFT JOIN sys.extended_properties ep ON ep.major_id = o.object_id AND ep.minor_id = sc.column_id AND ep.name = 'MS_Description'
            WHERE c.TABLE_NAME = ?
            ORDER BY c.ORDINAL_POSITION
        ", [$tableName]);

        $defaultCollation = $this->sqlServerDefaultCollation();

        return array_map(function ($col) use ($tableName, $defaultCollation) {
            $dataType = strtolower($col->data_type ?? '');
            $baseType = $this->normalizeType($dataType);

            $allowedValues = $this->parseSqlServerCheckConstraints($tableName, $col->name);
            if ($allowedValues !== []) {
                $baseType = 'enum';
            }

            $autoIncrement = (bool) ($col->is_identity ?? false);
            $default = $this->cleanSqlServerDefault($col->column_default, $baseType, $autoIncrement);

            $length = null;
            if (in_array($baseType, ['varchar', 'char'], true) && $col->character_maximum_length !== null && (int) $col->character_maximum_length > 0) {
                $length = (int) $col->character_maximum_length;
            }

            return [
                'name' => $col->name,
                'type' => $baseType,
                'nullable' => $col->is_nullable === 'YES',
                'default' => $default,
                'length' => $length,
                'unsigned' => false,
                'collation' => ($col->collation_name ?? null) !== null && $col->collation_name !== $defaultCollation
                    ? $col->collation_name
                    : null,
                'comment' => ($col->comment ?? '') !== '' ? (string) $col->comment : null,
                'auto_increment' => $autoIncrement,
                'allowed_values' => $allowedValues,
            ];
        }, $columns);
    }

    /**
     * Get indexes for a SQL Server table.
     *
     * @return array<int, array{key_name: string, index_type: string, columns: array<string>}>
     */
    protected function getSqlServerIndexes(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $indexes = $connection->select('
            SELECT
                i.name AS index_name,
                i.is_unique,
                i.is_primary_key,
                c.name AS column_name
            FROM sys.indexes i
            JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
            JOIN sys.columns c ON c.object_id = ic.object_id AND c.column_id = ic.column_id
            JOIN sys.tables t ON t.object_id = i.object_id
            WHERE t.name = ? AND i.name IS NOT NULL
            ORDER BY i.name, ic.key_ordinal
        ', [$tableName]);

        $grouped = [];
        foreach ($indexes as $idx) {
            $name = $idx->index_name;

            if (! isset($grouped[$name])) {
                $type = 'index';
                if ($idx->is_primary_key) {
                    $type = 'primary';
                } elseif ($idx->is_unique) {
                    $type = 'unique';
                }

                $grouped[$name] = [
                    'index_type' => $type,
                    'columns' => [],
                ];
            }

            $grouped[$name]['columns'][] = $idx->column_name;
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
     * Get foreign keys for a SQL Server table.
     *
     * @return array<int, array{name: string, column: string, referenced_table: string, referenced_column: string, on_update: ?string, on_delete: ?string}>
     */
    protected function getSqlServerForeignKeys(string $tableName): array
    {
        $connection = DB::connection($this->connectionName);

        $fks = $connection->select('
            SELECT
                fk.name AS constraint_name,
                c1.name AS column_name,
                t2.name AS referenced_table,
                c2.name AS referenced_column,
                fk.delete_referential_action_desc AS on_delete,
                fk.update_referential_action_desc AS on_update
            FROM sys.foreign_keys fk
            JOIN sys.tables t1 ON fk.parent_object_id = t1.object_id
            JOIN sys.foreign_key_columns fkc ON fk.object_id = fkc.constraint_object_id
            JOIN sys.columns c1 ON fkc.parent_object_id = c1.object_id AND fkc.parent_column_id = c1.column_id
            JOIN sys.tables t2 ON fk.referenced_object_id = t2.object_id
            JOIN sys.columns c2 ON fkc.referenced_object_id = c2.object_id AND fkc.referenced_column_id = c2.column_id
            WHERE t1.name = ?
        ', [$tableName]);

        return array_map(function ($fk) {
            $onDelete = str_replace('_', ' ', $fk->on_delete ?? '');
            $onUpdate = str_replace('_', ' ', $fk->on_update ?? '');

            return [
                'name' => $fk->constraint_name,
                'column' => $fk->column_name,
                'referenced_table' => $fk->referenced_table,
                'referenced_column' => $fk->referenced_column,
                'on_update' => $this->normalizeReferentialRule($onUpdate !== '' ? $onUpdate : null),
                'on_delete' => $this->normalizeReferentialRule($onDelete !== '' ? $onDelete : null),
            ];
        }, $fks);
    }

    /**
     * Get default collation for SQL Server database.
     */
    protected function sqlServerDefaultCollation(): ?string
    {
        try {
            $result = DB::connection($this->connectionName)->selectOne(
                "SELECT DATABASEPROPERTYEX(DB_NAME(), 'Collation') AS collation"
            );

            return $result->collation ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse CHECK constraints on a SQL Server column to extract enum-like allowed values.
     *
     * @return array<string>
     */
    protected function parseSqlServerCheckConstraints(string $tableName, string $columnName): array
    {
        try {
            $constraints = DB::connection($this->connectionName)->select('
                SELECT cc.definition
                FROM sys.check_constraints cc
                JOIN sys.columns col ON col.object_id = cc.parent_object_id AND col.column_id = cc.parent_column_id
                JOIN sys.tables t ON t.object_id = cc.parent_object_id
                WHERE t.name = ? AND col.name = ?
            ', [$tableName, $columnName]);

            foreach ($constraints as $c) {
                $def = $c->definition ?? '';
                if (preg_match('/IN\s*\(([^)]+)\)/i', $def, $m)) {
                    preg_match_all("/'([^']+)'/", $m[1], $matches);
                    if (! empty($matches[1])) {
                        return $matches[1];
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore errors
        }

        return [];
    }

    /**
     * Clean default value string for SQL Server.
     */
    protected function cleanSqlServerDefault(?string $default, string &$baseType, bool &$autoIncrement): mixed
    {
        if ($default === null) {
            return null;
        }

        $cleaned = trim($default);

        while (str_starts_with($cleaned, '(') && str_ends_with($cleaned, ')')) {
            $cleaned = trim(substr($cleaned, 1, -1));
        }

        if (strtolower($cleaned) === 'null') {
            return null;
        }

        if ($baseType === 'boolean') {
            if (in_array(strtolower($cleaned), ['1', "'1'", 'true'], true)) {
                return true;
            }
            if (in_array(strtolower($cleaned), ['0', "'0'", 'false'], true)) {
                return false;
            }
        }

        if (str_starts_with($cleaned, "'") && str_ends_with($cleaned, "'")) {
            return substr($cleaned, 1, -1);
        }

        return $cleaned;
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
                'comment' => $col->comment,
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
