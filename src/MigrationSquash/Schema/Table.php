<?php

namespace MigrationSquash\Schema;

class Table
{
    /**
     * @param  array<int, Column>  $columns
     * @param  array<int, Index>  $indexes
     * @param  array<int, ForeignKey>  $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {}

    /**
     * Add a column to this table.
     */
    public function addColumn(Column $column): self
    {
        $this->columns[] = $column;

        return $this;
    }

    /**
     * Add multiple columns to this table.
     *
     * @param  array<int, Column>  $columns
     */
    public function addColumns(array $columns): self
    {
        foreach ($columns as $column) {
            $this->columns[] = $column;
        }

        return $this;
    }

    /**
     * Add an index to this table.
     */
    public function addIndex(Index $index): self
    {
        $this->indexes[] = $index;

        return $this;
    }

    /**
     * Add a foreign key to this table.
     */
    public function addForeignKey(ForeignKey $foreignKey): self
    {
        $this->foreignKeys[] = $foreignKey;

        return $this;
    }

    /**
     * Get a column by name.
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
     * Get columns in order.
     *
     * @return array<int, Column>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Compare this table with another for equality.
     */
    public function equals(Table $other): bool
    {
        if ($this->name !== $other->name) {
            return false;
        }

        if (count($this->columns) !== count($other->columns)) {
            return false;
        }

        foreach ($this->columns as $i => $column) {
            if (! $column->equals($other->columns[$i])) {
                return false;
            }
        }

        if (count($this->indexes) !== count($other->indexes)) {
            return false;
        }

        foreach ($this->indexes as $i => $index) {
            if (! $index->equals($other->indexes[$i])) {
                return false;
            }
        }

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
     * Create a Table instance from database introspection.
     *
     * @param  array<int, array<string, mixed>>  $columnData  Normalized column arrays
     * @param  array<int, array<string, mixed>>  $indexData  Normalized index arrays
     * @param  array<int, array<string, mixed>>  $fkData  Normalized FK arrays
     */
    public static function fromDb(string $tableName, array $columnData, array $indexData, array $fkData): self
    {
        $table = new self(name: $tableName);

        foreach ($columnData as $col) {
            $table->addColumn(Column::fromDb($col));
        }

        foreach ($indexData as $idx) {
            $table->addIndex(Index::fromDb($idx));
        }

        foreach ($fkData as $fk) {
            $table->addForeignKey(ForeignKey::fromDb($fk));
        }

        return $table;
    }
}
