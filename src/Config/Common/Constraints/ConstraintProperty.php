<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Constraints;

final readonly class ConstraintProperty
{
    /**
     * @param string $name Attribute/relation name — the key for matching with SchemaAttribute/SchemaRelation
     * @param ConstraintRule[] $rules Base rules (always apply)
     * @param ConstraintRule[] $create Additional rules on create
     * @param ConstraintRule[] $update Additional rules on update
     * @param ConstraintRule[] $each Rules for an element of a list type (scalar arrays only)
     */
    public function __construct(
        public string $name,
        public array $rules = [],
        public array $create = [],
        public array $update = [],
        public array $each = [],
    ) {
    }
}
