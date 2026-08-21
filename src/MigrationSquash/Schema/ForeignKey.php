<?php

namespace MigrationSquash\Schema;

class ForeignKey
{
    public function __construct(
        public readonly string $name,
        public readonly string $column,
        public readonly string $referencedTable,
        public readonly string $referencedColumn,
        public readonly ?string $onUpdate = null,
        public readonly ?string $onDelete = null,
    ) {}

    /**
     * Compare this foreign key with another for equality
     */
    public function equals(ForeignKey $other): bool
    {
        return $this->name === $other->name
            && $this->column === $other->column
            && $this->referencedTable === $other->referencedTable
            && $this->referencedColumn === $other->referencedColumn
            && $this->onUpdate === $other->onUpdate
            && $this->onDelete === $other->onDelete;
    }
}
