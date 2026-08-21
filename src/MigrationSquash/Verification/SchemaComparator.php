<?php

namespace MigrationSquash\Verification;

use MigrationSquash\Schema\Table;

class SchemaComparator
{
    /**
     * Compare two schema snapshots for equality
     * 
     * @param array<string, mixed> $snapshotBefore Original schema snapshot
     * @param array<string, mixed> $snapshotAfter Generated schema snapshot
     * @return SchemaDiff Diff report with any differences found
     */
    public function compare(array $snapshotBefore, array $snapshotAfter): SchemaDiff
    {
        $diff = new SchemaDiff();
        
        // Check if all tables in before exist in after
        foreach ($snapshotBefore['tables'] as $tableName => $tableData) {
            if (! isset($snapshotAfter['tables'][$tableName])) {
                $diff->add($tableName, 'table_missing', []);
                continue;
            }
            
            $this->compareTable($tableData, $snapshotAfter['tables'][$tableName], $diff);
        }
        
        // Check for extra tables in after
        foreach ($snapshotAfter['tables'] as $tableName => $tableData) {
            if (! isset($snapshotBefore['tables'][$tableName])) {
                $diff->add($tableName, 'extra_table', []);
            }
        }
        
        return $diff;
    }

    /**
     * Compare two tables
     */
    protected function compareTable(array $before, array $after, SchemaDiff $diff): void
    {
        // Compare columns
        $this->compareColumns($before, $after, $diff);
        
        // Compare indexes
        $this->compareIndexes($before, $after, $diff);
        
        // Compare foreign keys
        $this->compareForeignKeys($before, $after, $diff);
    }

    /**
     * Compare columns between two table definitions
     */
    protected function compareColumns(array $before, array $after, SchemaDiff $diff): void
    {
        $beforeCols = [];
        $afterCols = [];
        
        foreach ($before['columns'] as $col) {
            $beforeCols[$col['name']] = $col;
        }
        
        foreach ($after['columns'] as $col) {
            $afterCols[$col['name']] = $col;
        }
        
        // Check for missing columns
        foreach ($beforeCols as $name => $col) {
            if (! isset($afterCols[$name])) {
                $diff->add(
                    $before['name'],
                    'column_missing',
                    ['column' => $name]
                );
                continue;
            }
            
            // Check column attributes
            $this->compareColumnAttributes($col, $afterCols[$name], $diff);
        }
        
        // Check for extra columns
        foreach ($afterCols as $name => $col) {
            if (! isset($beforeCols[$name])) {
                $diff->add(
                    $before['name'],
                    'extra_column',
                    ['column' => $name]
                );
            }
        }
    }

    /**
     * Compare individual column attributes
     */
    protected function compareColumnAttributes(array $before, array $after, SchemaDiff $diff): void
    {
        $attributes = [
            'type' => 'column_type_mismatch',
            'nullable' => 'column_nullable_mismatch',
            'default' => 'column_default_mismatch',
            'unsigned' => 'column_unsigned_mismatch',
            'length' => 'column_length_mismatch',
            'collation' => 'column_collation_mismatch',
        ];
        
        foreach ($attributes as $attr => $diffType) {
            if ($before[$attr] !== $after[$attr]) {
                $diff->add(
                    $before['name'],
                    $diffType,
                    [
                        'column' => $before['name'],
                        'expected' => var_export($before[$attr], true),
                        'actual' => var_export($after[$attr], true),
                    ]
                );
            }
        }
    }

    /**
     * Compare indexes between two table definitions
     */
    protected function compareIndexes(array $before, array $after, SchemaDiff $diff): void
    {
        $beforeIdxs = [];
        $afterIdxs = [];
        
        foreach ($before['indexes'] as $idx) {
            if ($idx['type'] !== 'primary') { // Skip primary key (usually handled separately)
                $beforeIdxs[$idx['name']] = $idx;
            }
        }
        
        foreach ($after['indexes'] as $idx) {
            if ($idx['type'] !== 'primary') {
                $afterIdxs[$idx['name']] = $idx;
            }
        }
        
        // Check for missing indexes
        foreach ($beforeIdxs as $name => $idx) {
            if (! isset($afterIdxs[$name])) {
                $diff->add(
                    $before['name'],
                    'index_missing',
                    ['index' => $name]
                );
            } elseif ($idx['columns'] !== $afterIdxs[$name]['columns']) {
                $diff->add(
                    $before['name'],
                    'index_columns_mismatch',
                    [
                        'index' => $name,
                        'expected' => implode(',', $idx['columns']),
                        'actual' => implode(',', $afterIdxs[$name]['columns']),
                    ]
                );
            }
        }
        
        // Check for extra indexes
        foreach ($afterIdxs as $name => $idx) {
            if (! isset($beforeIdxs[$name])) {
                $diff->add(
                    $before['name'],
                    'extra_index',
                    ['index' => $name]
                );
            }
        }
    }

    /**
     * Compare foreign keys between two table definitions
     */
    protected function compareForeignKeys(array $before, array $after, SchemaDiff $diff): void
    {
        $beforeFks = [];
        $afterFks = [];
        
        foreach ($before['foreign_keys'] as $fk) {
            $beforeFks[$fk['name']] = $fk;
        }
        
        foreach ($after['foreign_keys'] as $fk) {
            $afterFks[$fk['name']] = $fk;
        }
        
        // Check for missing foreign keys
        foreach ($beforeFks as $name => $fk) {
            if (! isset($afterFks[$name])) {
                $diff->add(
                    $before['name'],
                    'foreign_key_missing',
                    ['fk' => $name]
                );
                continue;
            }
            
            // Check FK attributes
            $attrs = ['referenced_table', 'referenced_column', 'on_delete', 'on_update'];
            foreach ($attrs as $attr) {
                if ($fk[$attr] !== $afterFks[$name][$attr]) {
                    $diff->add(
                        $before['name'],
                        'foreign_key_attribute_mismatch',
                        [
                            'fk' => $name,
                            'attribute' => $attr,
                            'expected' => $fk[$attr],
                            'actual' => $afterFks[$name][$attr],
                        ]
                    );
                }
            }
        }
        
        // Check for extra foreign keys
        foreach ($afterFks as $name => $fk) {
            if (! isset($beforeFks[$name])) {
                $diff->add(
                    $before['name'],
                    'extra_foreign_key',
                    ['fk' => $name]
                );
            }
        }
    }
}
