<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

final readonly class SchemaModel
{
    /**
     * @param string $type Model identifier
     * @param SchemaAttribute[] $attributes
     * @param SchemaRelation[] $relations
     * @param string|null $comment Comment
     */
    public function __construct(
        public string $type,
        public array $attributes = [],
        public array $relations = [],
        public ?string $comment = null,
    ) {
    }
}
