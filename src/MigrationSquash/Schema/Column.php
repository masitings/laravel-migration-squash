<?php

namespace MigrationSquash\Schema;

class Column
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $nullable,
        public readonly mixed $default,
        public readonly ?int $length = null,
        public readonly bool $unsigned = false,
        public readonly ?string $collation = null,
        public readonly ?string $autoIncrement = null,
        public readonly ?bool $virtual = null,
        public readonly ?bool $computed = null,
    ) {}

    /**
     * Compare this column with another for equality
     */
    public function equals(Column $other): bool
    {
        return $this->name === $other->name
            && $this->type === $other->type
            && $this->nullable === $other->nullable
            && $this->default === $other->default
            && $this->length === $other->length
            && $this->unsigned === $other->unsigned
            && $this->collation === $other->collation
            && $this->autoIncrement === $other->autoIncrement
            && $this->virtual === $other->virtual
            && $this->computed === $other->computed;
    }

    /**
     * Create a Column instance from database column info
     */
    public static function fromDb(array $columnInfo): self
    {
        return new self(
            name: $columnInfo['name'] ?? '',
            type: $columnInfo['type'] ?? 'unknown',
            nullable: ($columnInfo['nullable'] ?? false) === true || 
                      ($columnInfo['null'] ?? 'YES') === 'YES',
            default: $columnInfo['default'] ?? null,
            length: isset($columnInfo['length']) ? (int)$columnInfo['length'] : null,
            unsigned: ($columnInfo['unsigned'] ?? false) === true,
            collation: $columnInfo['collation'] ?? null,
            autoIncrement: isset($columnInfo['auto_increment']) ? 
                           ($columnInfo['auto_increment'] === true || 
                            $columnInfo['auto_increment'] === 1) : null,
            virtual: $columnInfo['extra'] ?? null === 'VIRTUAL' ? true : null,
            computed: ($columnInfo['extra'] ?? '') === 'ON UPDATE CURRENT_TIMESTAMP',
        );
    }
}
