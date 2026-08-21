<?php

namespace MigrationSquash\Introspection;

use MigrationSquash\Schema\Table;

class SchemaIntrospector
{
    protected string $connectionName;

    public function __construct(string $connectionName = 'sqlite')
    {
        $this->connectionName = $connectionName;
    }

    /**
     * Introspect the entire database schema
     * 
     * @return array<string, Table> Map of table name -> Table object
     */
    public function introspect(): array
    {
        if ($this->connectionName === 'mysql' || 
            config('database.default') === 'mysql') {
            return $this->introspectMySQL();
        }
        
        // Default to SQLite
        return $this->introspectSQLite();
    }

    /**
     * Introspect MySQL database
     */
    protected function introspectMySQL(): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $tables = [];
        
        // Get all tables except migrations table (if exists)
        try {
            $allTables = DB::select("SHOW TABLES");
            $tableNames = array_column($allTables, array_key_first((array)reset($allTables)));
            
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
                    $foreignKeys
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to introspect MySQL schema: {$e->getMessage()}");
        }
        
        return $tables;
    }

    /**
     * Introspect SQLite database
     */
    protected function introspectSQLite(): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $tables = [];
        
        try {
            // Get all tables
            $results = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            $tableNames = array_column($results, 'name');
            
            foreach ($tableNames as $tableName) {
                if ($tableName === 'migrations') {
                    continue;
                }
                
                $columns = $this->getSQLiteColumns($tableName);
                $indexes = $this->getSQLiteIndexes($tableName);
                $foreignKeys = []; // SQLite doesn't show FKs in same way
                
                $tables[$tableName] = Table::fromDb(
                    $tableName,
                    $columns,
                    $indexes,
                    $foreignKeys
                );
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to introspect SQLite schema: {$e->getMessage()}");
        }
        
        return $tables;
    }

    /**
     * Get columns for a MySQL table
     */
    protected function getMySQLColumns(string $tableName): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $columns = DB::select("DESCRIBE `{$tableName}`");
        
        return array_map(function($col) {
            return [
                'name' => $col->Field,
                'type' => $col->Type,
                'null' => $col->Null,
                'key' => $col->Key,
                'default' => $col->Default,
                'extra' => $col->Extra,
            ];
        }, $columns);
    }

    /**
     * Get indexes for a MySQL table
     */
    protected function getMySQLIndexes(string $tableName): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $indexes = DB::select("SHOW INDEXES FROM `{$tableName}`");
        
        // Group by index name
        $grouped = [];
        foreach ($indexes as $idx) {
            $groupName = $idx->Key_name;
            if (! isset($grouped[$groupName])) {
                $grouped[$groupName] = [
                    'index_type' => $idx->Index_type,
                    'columns' => [],
                    'non_unique' => $idx->Non_unique,
                ];
            }
            
            $grouped[$groupName]['columns'][] = $idx->Column_name;
        }
        
        return array_map(function($data, $name) {
            return [
                'key_name' => $name,
                'index_type' => $data['index_type'],
                'columns' => $data['columns'],
                'options' => ['non_unique' => (bool)$data['non_unique']],
            ];
        }, $grouped, array_keys($grouped));
    }

    /**
     * Get foreign keys for a MySQL table
     */
    protected function getMySQLForeignKeys(string $tableName): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $fks = DB::select("
            SELECT 
                COLUMN_NAME as column_name,
                REFERENCED_TABLE_NAME as referenced_table_name,
                REFERENCED_COLUMN_NAME as referenced_column_name,
                UPDATE_RULE as on_update,
                DELETE_RULE as on_delete
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '{$tableName}'
                AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        
        return array_map(fn($fk) => (array)$fk, $fks);
    }

    /**
     * Get columns for a SQLite table
     */
    protected function getSQLiteColumns(string $tableName): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $pragma = DB::select("PRAGMA table_info(`{$tableName}`)");
        
        return array_map(fn($col) => (array)$col, $pragma);
    }

    /**
     * Get indexes for a SQLite table
     */
    protected function getSQLiteIndexes(string $tableName): array
    {
        use \Illuminate\Support\Facades\DB;
        
        $pragma = DB::select("PRAGMA index_list(`{$tableName}`)");
        
        return array_map(fn($idx) => (array)$idx, $pragma);
    }

    /**
     * Get a snapshot of the current schema state
     * 
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        $tables = $this->introspect();
        
        return [
            'tables' => array_map(fn($table) => $this->serializeTable($table), $tables),
        ];
    }

    /**
     * Serialize a Table object for comparison
     */
    protected function serializeTable(Table $table): array
    {
        return [
            'name' => $table->name,
            'columns' => array_map(fn($col) => [
                'name' => $col->name,
                'type' => $col->type,
                'nullable' => $col->nullable,
                'default' => $col->default,
                'length' => $col->length,
                'unsigned' => $col->unsigned,
                'collation' => $col->collation,
                'auto_increment' => $col->autoIncrement,
            ], $table->columns),
            'indexes' => array_map(fn($idx) => [
                'name' => $idx->name,
                'type' => $idx->type,
                'columns' => $idx->columns,
                'options' => $idx->options,
            ], $table->indexes),
            'foreign_keys' => array_map(fn($fk) => [
                'name' => $fk->name,
                'column' => $fk->column,
                'referenced_table' => $fk->referencedTable,
                'referenced_column' => $fk->referencedColumn,
                'on_update' => $fk->onUpdate,
                'onDelete' => $fk->onDelete,
            ], $table->foreignKeys),
        ];
    }
}
