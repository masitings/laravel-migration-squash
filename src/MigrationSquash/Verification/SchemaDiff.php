<?php

namespace MigrationSquash\Verification;

class SchemaDiff
{
    /**
     * @var array<int, array<string, mixed>> List of differences found
     */
    public array $differences = [];

    /**
     * Add a difference to the diff report
     */
    public function add(string $table, string $type, array $details): void
    {
        $this->differences[] = compact('table', 'type', ...$details);
    }

    /**
     * Check if the diff is empty (schemas are identical)
     */
    public function isEmpty(): bool
    {
        return empty($this->differences);
    }

    /**
     * Get formatted error message
     */
    public function formatMessage(): string
    {
        if ($this->isEmpty()) {
            return "✅ Schemas match perfectly!";
        }

        $message = "❌ Found " . count($this->differences) . " schema difference(s):\n\n";
        
        foreach ($this->differences as $diff) {
            $message .= "- [{$diff['table']}] {$diff['type']}";
            
            switch ($diff['type']) {
                case 'column_missing':
                    $message .= ": Column '{$diff['column']}' missing";
                    break;
                case 'column_type_mismatch':
                    $message .= ": {$diff['column']} type mismatch (expected {$diff['expected']}, got {$diff['actual']})";
                    break;
                case 'column_nullable_mismatch':
                    $message .= ": {$diff['column']} nullability mismatch (expected nullable={$diff['expected']}, got nullable={$diff['actual']})";
                    break;
                case 'index_missing':
                    $message .= ": Index '{$diff['index']}' missing";
                    break;
                case 'foreign_key_missing':
                    $message .= ": Foreign key '{$diff['fk']}' missing";
                    break;
                default:
                    $message .= ": " . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($diff), array_values($diff)));
            }
            
            $message .= "\n";
        }

        return $message;
    }

    /**
     * Serialize differences for display
     */
    public function serialize(): array
    {
        return $this->differences;
    }
}
