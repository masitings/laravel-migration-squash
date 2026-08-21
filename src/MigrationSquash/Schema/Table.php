<?php

namespace MigrationSquash\Schema;

class Table
{
    public function __construct(
        public readonly string $name,
        public readonly array $columns = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
    ) {}

    /**
     * Add a column to this table
     */
    public function addColumn(Column $column): self
    {
        $this->columns[] = $column;
        return $this;
    }

    /**
     * Add multiple columns to this table
     */
    public function addColumns(array $columns): self
    {
        foreach ($columns as $column) {
            $this->columns[] = $column;
        }
        return $this;
    }

    /**
     * Get a column by name
     */
    public function getColumn(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }
        
        return null;
    }

    /**
     * Get columns in order
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Compare this table with another for equality
     */
    public function equals(Table $other): bool
    {
        if ($this->name !== $other->name) {
            return false;
        }

        // Check column count
        if (count($this->columns) !== count($other->columns)) {
            return false;
        }

        // Check each column
        foreach ($this->columns as $i => $column) {
            if (! $column->equals($other->columns[$i])) {
                return false;
            }
        }

        // Check indexes
        if (count($this->indexes) !== count($other->indexes)) {
            return false;
        }
        
        foreach ($this->indexes as $i => $index) {
            if (! $index->equals($other->indexes[$i])) {
                return false;
            }
        }

        // Check foreign keys
        if (count($this->foreignKeys) !== count($other->foreignKeys)) {
            return false;
        }
        
        foreach ($this->foreignKeys as $i => $fk) {
            if (! $fk->equals($other->foreignKeys[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create a Table instance from database introspection
     */
    public static function fromDb(string $tableName, array $columnData, array $indexData, array $fkData): self
    {
        $table = new self(name: $tableName);

        foreach ($columnData as $col) {
            $table->addColumn(Column::fromDb($col));
        }

        foreach ($indexData as $idx) {
            $table->indexes[] = Index::fromDb($idx);
        }

        foreach ($fkData as $fk) {
            $table->foreignKeys[] = ForeignKey::fromDb($fk);
        }

        return $table;
    }
}
