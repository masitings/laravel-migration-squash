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
     * Compare this foreign key with another for equality.
     */
    public function equals(ForeignKey $other): bool
    {
        return $this->column === $other->column
            && $this->referencedTable === $other->referencedTable
            && $this->referencedColumn === $other->referencedColumn
            && $this->onUpdate === $other->onUpdate
            && $this->onDelete === $other->onDelete;
    }

    /**
     * Create a ForeignKey instance from a normalized FK info array.
     *
     * @param  array{name: string, column: string, referenced_table: string, referenced_column: string, on_update: ?string, on_delete: ?string}  $fkInfo
     */
    public static function fromDb(array $fkInfo): self
    {
        return new self(
            name: $fkInfo['name'] ?? '',
            column: $fkInfo['column'] ?? '',
            referencedTable: $fkInfo['referenced_table'] ?? '',
            referencedColumn: $fkInfo['referenced_column'] ?? '',
            onUpdate: $fkInfo['on_update'] ?? null,
            onDelete: $fkInfo['on_delete'] ?? null,
        );
    }
}
