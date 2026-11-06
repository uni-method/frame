<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

final readonly class Model
{
    /**
     * @param Attribute[] $attributes
     * @param Relation[] $relations
     * @param string|null $entityLocator Alias of the locator used to load
     *                                   the model by id (references, filters by relation)
     */
    public function __construct(
        public string $class,
        public string $type,
        public ?string $comment = null,
        public array $attributes = [],
        public array $relations = [],
        public ?string $entityLocator = null,
    ) {
    }
}
