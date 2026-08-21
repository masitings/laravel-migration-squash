<?php

namespace MigrationSquash\Generation;

use MigrationSquash\Schema\Table;

class SquashedMigrationGenerator
{
    protected string $stubPath;

    public function __construct(?string $stubPath = null)
    {
        $this->stubPath = $stubPath ?? __DIR__ . '/Stubs/squashed-table.stub';
    }

    /**
     * Generate a squashed migration file for a single table
     * 
     * @param  Table  $table
     * @param  string  $migrationName
     * @return string Generated PHP code
     */
    public function generate(Table $table, string $migrationName): string
    {
        $timestamp = $this->generateTimestamp();
        $tableName = $table->name;
        
        $code = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

        return new class extends Migration
    {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint $table) {
            // Columns
            {$this->generateColumns($table)}

            // Primary key
            $table->primary('{$this->getPrimaryKeyColumn($table)}');

            // Indexes
            {$this->generateIndexes($table)}

            // Foreign keys
            {$this->generateForeignKeys($table)}
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;

        return $code;
    }

    /**
     * Generate column definitions
     */
    protected function generateColumns(Table $table): string
    {
        $lines = [];
        
        foreach ($table->columns as $column) {
            $definition = $this->getColumnDefinition($column);
            if ($definition) {
                $lines[] = "            {$definition},";
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * Get the primary key column name
     */
    protected function getPrimaryKeyColumn(Table $table): string
    {
        // Default to 'id' or first column
        if ($table->getColumn('id')) {
            return 'id';
        }
        
        if (! empty($table->columns)) {
            return $table->columns[0]->name;
        }
        
        return 'id'; // fallback
    }

    /**
     * Generate index definitions
     */
    protected function generateIndexes(Table $table): string
    {
        $lines = [];
        
        foreach ($table->indexes as $index) {
            // Skip primary key (handled separately)
            if ($index->type === 'primary') {
                continue;
            }
            
            $columns = implode(', ', $index->columns);
            
            if ($index->type === 'unique') {
                $lines[] = "            \$table->unique({$columns});";
            } elseif ($index->type === 'index') {
                $lines[] = "            \$table->index({$columns});";
            } elseif ($index->type === 'fulltext') {
                $lines[] = "            \$table->fullText({$columns});";
            }
        }
        
        return implode("\n", $lines);
    }

    /**
     * Generate foreign key definitions
     */
    protected function generateForeignKeys(Table $table): string
    {
        $lines = [];
        
        foreach ($table->foreignKeys as $fk) {
            $column = $fk->column;
            $foreign = $fk->referencedColumn;
            $onTable = $fk->referencedTable;
            
            $onDelete = $fk->onDelete ? ", '{$fk->onDelete}'" : '';
            $onUpdate = $fk->onUpdate ? ", '{$fk->onUpdate}'" : '';
            
            $lines[] = "            \$table->foreign({$column})->references({$foreign})->on({$onTable}){$onDelete}{$onUpdate};";
        }
        
        return implode("\n", $lines);
    }

    /**
     * Get column definition from Column object
     */
    protected function getColumnDefinition(\MigrationSquash\Schema\Column $column): ?string
    {
        $name = $column->name;
        $type = $column->type;
        
        // Map Laravel types
        $definition = match ($type) {
            'bigint', 'int', 'integer', 'smallint', 'mediumint', 'tinyint' => "\$table->{$type}(\'{$name}\')",
            'decimal' => "\$table->decimal(\'{$name}\', {$column->length})",
            'float' => "\$table->float(\'{$name}\')",
            'double' => "\$table->double(\'{$name}\')",
            'boolean' => "\$table->boolean(\'{$name}\')",
            'enum' => "\$table->enum(\'{$name}\', [])", // Need enum values
            default => "\$table->string(\'{$name}\')",
        };
        
        // Add modifiers
        if ($column->unsigned) {
            $definition .= '->unsigned()';
        }
        
        if ($column->nullable) {
            $definition .= '->nullable()';
        }
        
        if ($column->autoIncrement) {
            $definition .= '->autoIncrement()';
        }
        
        if ($column->default !== null) {
            $defaultValue = var_export($column->default, true);
            $definition .= "->default({$defaultValue})";
        }
        
        if ($column->collation) {
            $definition .= "->collation('{\$column->collation}')";
        }
        
        return $definition;
    }

    /**
     * Generate timestamp based on current time
     */
    protected function generateTimestamp(): string
    {
        return date('Y_m_d_His');
    }
}
