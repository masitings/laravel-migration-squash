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
        public readonly bool $autoIncrement = false,
        public readonly ?bool $virtual = null,
        public readonly ?bool $computed = null,
        /** @var array<int, string> Allowed values for enum/set columns */
        public readonly array $allowedValues = [],
        public readonly ?string $comment = null,
    ) {}

    /**
     * Compare this column with another for equality.
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
            && $this->computed === $other->computed
            && $this->allowedValues === $other->allowedValues
            && $this->comment === $other->comment;
    }

    /**
     * Create a Column instance from a normalized database column info array.
     *
     * Expects keys: name, type, nullable (bool), default, length (?int),
     * unsigned (bool), collation (?string), auto_increment (bool),
     * allowed_values (array<string>, enum/set only).
     *
     * @param  array{name: string, type: string, nullable: bool, default: mixed, length: ?int, unsigned: bool, collation: ?string, auto_increment: bool}  $columnInfo
     */
    public static function fromDb(array $columnInfo): self
    {
        return new self(
            name: $columnInfo['name'] ?? '',
            type: $columnInfo['type'] ?? 'unknown',
            nullable: (bool) ($columnInfo['nullable'] ?? false),
            default: $columnInfo['default'] ?? null,
            length: isset($columnInfo['length']) ? (int) $columnInfo['length'] : null,
            unsigned: (bool) ($columnInfo['unsigned'] ?? false),
            collation: $columnInfo['collation'] ?? null,
            autoIncrement: (bool) ($columnInfo['auto_increment'] ?? false),
            virtual: isset($columnInfo['extra']) && ($columnInfo['extra'] === 'VIRTUAL') ? true : null,
            computed: isset($columnInfo['extra']) && ($columnInfo['extra'] === 'ON UPDATE CURRENT_TIMESTAMP'),
            allowedValues: $columnInfo['allowed_values'] ?? [],
            comment: $columnInfo['comment'] ?? null,
        );
    }
}
