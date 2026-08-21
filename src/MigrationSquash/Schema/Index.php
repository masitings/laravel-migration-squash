<?php

namespace MigrationSquash\Schema;

class Index
{
    public function __construct(
        public readonly string $name,
        public readonly string $type, // 'primary', 'unique', 'index', 'fulltext'
        public readonly array $columns,
        public readonly ?array $options = null,
    ) {}

    /**
     * Compare this index with another for equality
     */
    public function equals(Index $other): bool
    {
        return $this->name === $other->name
            && $this->type === $other->type
            && $this->columns === $other->columns
            && $this->options === $other->options;
    }

    /**
     * Create an Index instance from database index info
     */
    public static function fromDb(array $indexInfo): self
    {
        return new self(
            name: $indexInfo['key_name'] ?? '',
            type: $indexInfo['index_type'] ?? 'index',
            columns: $indexInfo['columns'] ?? [],
            options: isset($indexInfo['options']) ? $indexInfo['options'] : null,
        );
    }
}
