<?php

namespace MigrationSquash\Verification;

class SchemaDiff
{
    /**
     * @var array<int, array{table: string, type: string, details: array<string, mixed>}> List of differences found
     */
    public array $differences = [];

    /**
     * Add a difference to the diff report.
     *
     * @param  array<string, mixed>  $details  Contextual details about the difference
     */
    public function add(string $table, string $type, array $details): void
    {
        $this->differences[] = [
            'table' => $table,
            'type' => $type,
            'details' => $details,
        ];
    }

    /**
     * Check if the diff is empty (schemas are identical).
     */
    public function isEmpty(): bool
    {
        return empty($this->differences);
    }

    /**
     * Get formatted error message.
     */
    public function formatMessage(): string
    {
        if ($this->isEmpty()) {
            return '✅ Schemas match perfectly!';
        }

        $message = '❌ Found '.count($this->differences)." schema difference(s):\n\n";

        foreach ($this->differences as $diff) {
            $details = $diff['details'];
            $message .= "- [{$diff['table']}] {$diff['type']}";

            $message .= match ($diff['type']) {
                'table_missing' => ': Table missing from generated schema',
                'extra_table' => ': Extra table in generated schema',
                'column_missing' => ": Column '{$details['column']}' missing",
                'extra_column' => ": Extra column '{$details['column']}'",
                'column_type_mismatch' => ": {$details['column']} type mismatch (expected {$details['expected']}, got {$details['actual']})",
                'column_nullable_mismatch' => ": {$details['column']} nullability mismatch (expected nullable={$details['expected']}, got nullable={$details['actual']})",
                'column_default_mismatch' => ": {$details['column']} default mismatch (expected {$details['expected']}, got {$details['actual']})",
                'column_unsigned_mismatch' => ": {$details['column']} unsigned mismatch (expected {$details['expected']}, got {$details['actual']})",
                'column_length_mismatch' => ": {$details['column']} length mismatch (expected {$details['expected']}, got {$details['actual']})",
                'column_collation_mismatch' => ": {$details['column']} collation mismatch (expected {$details['expected']}, got {$details['actual']})",
                'column_enum_values_mismatch' => ": {$details['column']} enum values mismatch (expected {$details['expected']}, got {$details['actual']})",
                'index_missing' => ": Index missing (columns: {$details['columns']})",
                'extra_index' => ": Extra index (columns: {$details['columns']})",
                'index_columns_mismatch' => ": Index columns mismatch (expected {$details['expected']}, got {$details['actual']})",
                'foreign_key_missing' => ": Foreign key missing ({$details['column']} -> {$details['referenced_table']})",
                'extra_foreign_key' => ": Extra foreign key ({$details['column']} -> {$details['referenced_table']})",
                'foreign_key_attribute_mismatch' => ": Foreign key attribute mismatch ({$details['attribute']}: expected {$details['expected']}, got {$details['actual']})",
                default => ': '.json_encode($details),
            };

            $message .= "\n";
        }

        return $message;
    }

    /**
     * Serialize differences for programmatic access.
     *
     * @return array<int, array{table: string, type: string, details: array<string, mixed>}>
     */
    public function serialize(): array
    {
        return $this->differences;
    }
}
