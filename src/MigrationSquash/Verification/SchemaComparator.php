<?php

namespace MigrationSquash\Verification;

class SchemaComparator
{
    /**
     * Compare two schema snapshots for equality.
     *
     * @param  array{tables: array<string, array<string, mixed>>}  $snapshotBefore  Original schema snapshot
     * @param  array{tables: array<string, array<string, mixed>>}  $snapshotAfter  Generated schema snapshot
     * @return SchemaDiff Diff report with any differences found
     */
    public function compare(array $snapshotBefore, array $snapshotAfter): SchemaDiff
    {
        $diff = new SchemaDiff;

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
     * Compare two tables.
     */
    protected function compareTable(array $before, array $after, SchemaDiff $diff): void
    {
        $this->compareColumns($before, $after, $diff);
        $this->compareIndexes($before, $after, $diff);
        $this->compareForeignKeys($before, $after, $diff);
    }

    /**
     * Compare columns between two table definitions.
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

        foreach ($beforeCols as $name => $col) {
            if (! isset($afterCols[$name])) {
                $diff->add($before['name'], 'column_missing', ['column' => $name]);

                continue;
            }

            $this->compareColumnAttributes($before['name'], $col, $afterCols[$name], $diff);
        }

        foreach ($afterCols as $name => $col) {
            if (! isset($beforeCols[$name])) {
                $diff->add($before['name'], 'extra_column', ['column' => $name]);
            }
        }
    }

    /**
     * Compare individual column attributes.
     */
    protected function compareColumnAttributes(string $tableName, array $before, array $after, SchemaDiff $diff): void
    {
        $attributes = [
            'type' => 'column_type_mismatch',
            'nullable' => 'column_nullable_mismatch',
            'default' => 'column_default_mismatch',
            'unsigned' => 'column_unsigned_mismatch',
            'length' => 'column_length_mismatch',
            'collation' => 'column_collation_mismatch',
            'allowed_values' => 'column_enum_values_mismatch',
            'comment' => 'column_comment_mismatch',
        ];

        foreach ($attributes as $attr => $diffType) {
            $beforeVal = $before[$attr] ?? null;
            $afterVal = $after[$attr] ?? null;

            if ($beforeVal !== $afterVal) {
                $diff->add($tableName, $diffType, [
                    'column' => $before['name'],
                    'expected' => $this->renderValue($beforeVal),
                    'actual' => $this->renderValue($afterVal),
                ]);
            }
        }
    }

    /**
     * Render an attribute value for a human-readable diff message.
     */
    protected function renderValue(mixed $value): string
    {
        if (is_array($value)) {
            return '['.implode(', ', array_map(fn ($v) => var_export($v, true), $value)).']';
        }

        return var_export($value, true);
    }

    /**
     * Compare indexes between two table definitions.
     *
     * Comparison is based on signature (sorted columns + type) rather than
     * index name, because generated names differ across drivers.
     */
    protected function compareIndexes(array $before, array $after, SchemaDiff $diff): void
    {
        $beforeSigs = $this->buildIndexSignatures($before['indexes'] ?? []);
        $afterSigs = $this->buildIndexSignatures($after['indexes'] ?? []);

        foreach ($beforeSigs as $sig => $idx) {
            if (! isset($afterSigs[$sig])) {
                $diff->add($before['name'], 'index_missing', [
                    'columns' => implode(',', $idx['columns']),
                    'type' => $idx['type'],
                ]);
            }
        }

        foreach ($afterSigs as $sig => $idx) {
            if (! isset($beforeSigs[$sig])) {
                $diff->add($before['name'], 'extra_index', [
                    'columns' => implode(',', $idx['columns']),
                    'type' => $idx['type'],
                ]);
            }
        }
    }

    /**
     * Build index signatures for comparison.
     *
     * Signature = "type:col1,col2,col3" (columns sorted).
     *
     * @return array<string, array{type: string, columns: array<string>}>
     */
    protected function buildIndexSignatures(array $indexes): array
    {
        $signatures = [];

        foreach ($indexes as $idx) {
            if (($idx['type'] ?? '') === 'primary') {
                continue;
            }

            $cols = $idx['columns'] ?? [];
            sort($cols);
            $sig = ($idx['type'] ?? 'index').':'.implode(',', $cols);
            $signatures[$sig] = $idx;
        }

        return $signatures;
    }

    /**
     * Compare foreign keys between two table definitions.
     *
     * Comparison is based on column + referenced_table + referenced_column
     * rather than FK name, because generated names differ across drivers.
     */
    protected function compareForeignKeys(array $before, array $after, SchemaDiff $diff): void
    {
        $beforeSigs = $this->buildFkSignatures($before['foreign_keys'] ?? []);
        $afterSigs = $this->buildFkSignatures($after['foreign_keys'] ?? []);

        foreach ($beforeSigs as $sig => $fk) {
            if (! isset($afterSigs[$sig])) {
                $diff->add($before['name'], 'foreign_key_missing', [
                    'column' => $fk['column'],
                    'referenced_table' => $fk['referenced_table'],
                ]);

                continue;
            }

            // Check FK attributes (on_update, on_delete)
            $attrs = ['on_update', 'on_delete'];

            foreach ($attrs as $attr) {
                $beforeVal = $fk[$attr] ?? null;
                $afterVal = $afterSigs[$sig][$attr] ?? null;

                if ($beforeVal !== $afterVal) {
                    $diff->add($before['name'], 'foreign_key_attribute_mismatch', [
                        'column' => $fk['column'],
                        'referenced_table' => $fk['referenced_table'],
                        'attribute' => $attr,
                        'expected' => $beforeVal ?? 'null',
                        'actual' => $afterVal ?? 'null',
                    ]);
                }
            }
        }

        foreach ($afterSigs as $sig => $fk) {
            if (! isset($beforeSigs[$sig])) {
                $diff->add($before['name'], 'extra_foreign_key', [
                    'column' => $fk['column'],
                    'referenced_table' => $fk['referenced_table'],
                ]);
            }
        }
    }

    /**
     * Build FK signatures for comparison.
     *
     * Signature = "column->referenced_table.referenced_column"
     *
     * @return array<string, array<string, mixed>>
     */
    protected function buildFkSignatures(array $foreignKeys): array
    {
        $signatures = [];

        foreach ($foreignKeys as $fk) {
            $sig = ($fk['column'] ?? '').'->'.($fk['referenced_table'] ?? '').'.'.($fk['referenced_column'] ?? '');
            $signatures[$sig] = $fk;
        }

        return $signatures;
    }
}
