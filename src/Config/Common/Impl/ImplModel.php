<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Impl;

final readonly class ImplModel
{
    /**
     * @param string $type Model identifier — the key for matching with SchemaModel
     * @param string $class PHP class of the entity
     * @param ImplAttribute[] $attributes
     * @param ImplRelation[] $relations
     * @param string|null $entityLocator Locator alias for loading the model by id
     */
    public function __construct(
        public string $type,
        public string $class,
        public array $attributes = [],
        public array $relations = [],
        public ?string $entityLocator = null,
    ) {
    }
}
