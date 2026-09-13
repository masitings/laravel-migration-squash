<?php

namespace MigrationSquash\Generation;

use MigrationSquash\Schema\Column;
use MigrationSquash\Schema\ForeignKey;
use MigrationSquash\Schema\Index;
use MigrationSquash\Schema\Table;

class SquashedMigrationGenerator
{
    protected string $tableStubPath;

    protected string $fkStubPath;

    public function __construct(?string $tableStubPath = null, ?string $fkStubPath = null)
    {
        $this->tableStubPath = $tableStubPath ?? __DIR__.'/Stubs/squashed-table.stub';
        $this->fkStubPath = $fkStubPath ?? __DIR__.'/Stubs/squashed-foreign-keys.stub';
    }

    /**
     * Generate a squashed migration file for a single table (without foreign keys).
     *
     * Foreign keys are generated separately via generateForeignKeyMigration()
     * to avoid circular dependency issues (D-3).
     */
    public function generate(Table $table, string $migrationName): string
    {
        $stub = file_get_contents($this->tableStubPath);

        $columnsCode = $this->generateColumns($table);
        $primaryKeyCode = $this->generatePrimaryKey($table);
        $indexesCode = $this->generateIndexes($table);

        return $this->fill($stub, [
            '{{TABLE_NAME}}' => $table->name,
            '{{COLUMNS}}' => $columnsCode,
            '{{PRIMARY_KEY}}' => $primaryKeyCode,
            '{{INDEXES}}' => $indexesCode,
        ]);
    }

    /**
     * Generate a single migration file containing all foreign keys for all tables.
     *
     * @param  array<string, Table>  $tables  Map of table name -> Table
     * @return string|null Generated PHP code, or null if no foreign keys exist
     */
    public function generateForeignKeyMigration(array $tables): ?string
    {
        $definitions = [];
        $drops = [];

        foreach ($tables as $table) {
            if (empty($table->foreignKeys)) {
                continue;
            }

            $tableDefinitions = [];

            foreach ($table->foreignKeys as $fk) {
                $line = "\$table->foreign('{$fk->column}')";
                $line .= "->references('{$fk->referencedColumn}')";
                $line .= "->on('{$fk->referencedTable}')";

                if ($fk->onDelete !== null) {
                    $line .= "->onDelete('{$fk->onDelete}')";
                }

                if ($fk->onUpdate !== null) {
                    $line .= "->onUpdate('{$fk->onUpdate}')";
                }

                $line .= ';';
                $tableDefinitions[] = "            {$line}";
            }

            $definitions[] = "        Schema::table('{$table->name}', function (Blueprint \$table) {\n"
                .implode("\n", $tableDefinitions)."\n"
                .'        });';

            // One dropForeign() per key. Passing every column in a single
            // array makes Laravel build ONE index name out of all of them
            // (comments_parent_id_project_id_user_id_foreign), which does not
            // exist, so the rollback fails.
            $dropLines = array_map(
                fn (ForeignKey $fk) => "            \$table->dropForeign(['{$fk->column}']);",
                $table->foreignKeys,
            );

            $drops[] = "        Schema::table('{$table->name}', function (Blueprint \$table) {\n"
                .implode("\n", $dropLines)."\n"
                .'        });';
        }

        if (empty($definitions)) {
            return null;
        }

        $stub = file_get_contents($this->fkStubPath);

        return $this->fill($stub, [
            '{{FOREIGN_KEY_DEFINITIONS}}' => implode("\n\n", $definitions),
            '{{FOREIGN_KEY_DROPS}}' => implode("\n\n", $drops),
        ]);
    }

    /**
     * Fill a stub, dropping any line that consists only of an empty placeholder.
     *
     * Placeholders that own a whole line (columns, primary key, indexes) carry
     * their own indentation in the generated code. Substituting an empty value
     * would otherwise leave a blank line full of stray whitespace behind, which
     * is a poor look for a tool whose output is meant to be a clean migration.
     *
     * @param  array<string, string>  $replacements
     */
    protected function fill(string $stub, array $replacements): string
    {
        foreach ($replacements as $token => $value) {
            if ($value === '') {
                // Remove the whole line, including its newline.
                $stub = preg_replace(
                    '/^[ \t]*'.preg_quote($token, '/').'[ \t]*\R/m',
                    '',
                    $stub,
                );
            }

            $stub = str_replace($token, $value, $stub);
        }

        return $stub;
    }

    /**
     * Generate column definitions.
     */
    protected function generateColumns(Table $table): string
    {
        $lines = [];

        foreach ($table->columns as $column) {
            $definition = $this->getColumnDefinition($column);

            if ($definition !== null) {
                $lines[] = "            {$definition};";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Generate primary key definition.
     */
    protected function generatePrimaryKey(Table $table): string
    {
        // Check if there's an explicit primary key index
        foreach ($table->indexes as $index) {
            if ($index->type === 'primary') {
                if (count($index->columns) === 1 && $index->columns[0] === 'id') {
                    // id() already handles primary key
                    return '';
                }

                $cols = array_map(fn ($c) => "'{$c}'", $index->columns);

                return '            $table->primary(['.implode(', ', $cols).']);';
            }
        }

        return '';
    }

    /**
     * Generate index definitions (excluding primary key).
     */
    protected function generateIndexes(Table $table): string
    {
        $lines = [];

        foreach ($table->indexes as $index) {
            if ($index->type === 'primary') {
                continue;
            }

            $cols = array_map(fn ($c) => "'{$c}'", $index->columns);
            $columnsArg = count($cols) === 1 ? $cols[0] : '['.implode(', ', $cols).']';

            $line = match ($index->type) {
                'unique' => "\$table->unique({$columnsArg});",
                'fulltext' => "\$table->fullText({$columnsArg});",
                default => "\$table->index({$columnsArg});",
            };

            $lines[] = "            {$line}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build an enum/set column definition.
     *
     * Falls back to string() when no allowed values are known, because
     * `enum('col', [])` renders as `ENUM()`, which is invalid SQL.
     */
    protected function getEnumDefinition(Column $column): string
    {
        $name = $column->name;

        if ($column->allowedValues === []) {
            return "\$table->string('{$name}')";
        }

        $values = array_map(
            fn (string $v) => "'".str_replace("'", "\\'", $v)."'",
            $column->allowedValues,
        );

        $method = $column->type === 'set' ? 'set' : 'enum';

        return "\$table->{$method}('{$name}', [".implode(', ', $values).'])';
    }

    /**
     * Get Laravel migration column definition from a Column object.
     */
    protected function getColumnDefinition(Column $column): ?string
    {
        $name = $column->name;
        $type = $column->type;

        // Handle auto-incrementing id columns specially
        if ($column->autoIncrement) {
            $idMethod = match ($type) {
                'bigint' => 'id',
                'integer', 'int' => 'increments',
                'smallint' => 'smallIncrements',
                'mediumint' => 'mediumIncrements',
                'tinyint' => 'tinyIncrements',
                default => 'id',
            };

            if ($idMethod === 'id') {
                return $name === 'id' ? '$table->id()' : "\$table->id('{$name}')";
            }

            return "\$table->{$idMethod}('{$name}')";
        }

        // Map type to Laravel method
        $definition = match ($type) {
            'bigint' => $column->unsigned ? "\$table->unsignedBigInteger('{$name}')" : "\$table->bigInteger('{$name}')",
            'integer', 'int' => $column->unsigned ? "\$table->unsignedInteger('{$name}')" : "\$table->integer('{$name}')",
            'smallint' => $column->unsigned ? "\$table->unsignedSmallInteger('{$name}')" : "\$table->smallInteger('{$name}')",
            'mediumint' => $column->unsigned ? "\$table->unsignedMediumInteger('{$name}')" : "\$table->mediumInteger('{$name}')",
            'tinyint' => $column->unsigned ? "\$table->unsignedTinyInteger('{$name}')" : "\$table->tinyInteger('{$name}')",
            'decimal' => "\$table->decimal('{$name}'".($column->length ? ", {$column->length}" : '').')',
            'float' => "\$table->float('{$name}')",
            'double' => "\$table->double('{$name}')",
            'boolean', 'bool' => "\$table->boolean('{$name}')",
            'varchar' => "\$table->string('{$name}'".($column->length ? ", {$column->length}" : '').')',
            'char' => "\$table->char('{$name}'".($column->length ? ", {$column->length}" : '').')',
            'text' => "\$table->text('{$name}')",
            'mediumtext' => "\$table->mediumText('{$name}')",
            'longtext' => "\$table->longText('{$name}')",
            'tinytext' => "\$table->tinyText('{$name}')",
            'blob' => "\$table->binary('{$name}')",
            'date' => "\$table->date('{$name}')",
            'datetime' => "\$table->dateTime('{$name}')",
            'timestamp' => "\$table->timestamp('{$name}')",
            'time' => "\$table->time('{$name}')",
            'year' => "\$table->year('{$name}')",
            'json' => "\$table->json('{$name}')",
            'enum', 'set' => $this->getEnumDefinition($column),
            'binary', 'varbinary' => "\$table->binary('{$name}')",
            default => "\$table->string('{$name}')",
        };

        // Add modifiers (unsigned already handled in match above for int types)
        if ($column->nullable) {
            $definition .= '->nullable()';
        }

        if ($column->default !== null) {
            if (is_string($column->default) && strtoupper($column->default) === 'CURRENT_TIMESTAMP') {
                $definition .= '->useCurrent()';
            } else {
                $defaultValue = var_export($column->default, true);
                $definition .= "->default({$defaultValue})";
            }
        }

        if ($column->collation !== null) {
            $definition .= "->collation('{$column->collation}')";
        }

        if ($column->comment !== null) {
            $definition .= "->comment('".str_replace("'", "\\'", $column->comment)."')";
        }

        return $definition;
    }
}
