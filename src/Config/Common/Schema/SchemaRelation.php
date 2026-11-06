<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

use UniMethod\Frame\Config\Mutators;

final readonly class SchemaRelation
{
    /**
     * @param string $name Relation name
     * @param string $type Relation type: oneToOne, oneToMany, manyToOne, manyToMany
     * @param string $targetModel Target model
     * @param Mutators|null $mutators Mutators by phase
     * @param string|null $comment Comment
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $targetModel,
        public ?Mutators $mutators = null,
        public ?string $comment = null,
    ) {
    }
}
