<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Constraints;

final readonly class ConstraintsModel
{
    /**
     * @param string $model Model identifier — the key for matching with SchemaModel
     * @param ConstraintProperty[] $attributes
     * @param ConstraintProperty[] $relations
     */
    public function __construct(
        public string $model,
        public array $attributes = [],
        public array $relations = [],
    ) {
    }
}
