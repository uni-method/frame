<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

final readonly class SchemaConfig
{
    /**
     * @param SchemaModel[] $models
     * @param SchemaAction[] $actions
     */
    public function __construct(
        public array $models = [],
        public array $actions = [],
    ) {
    }
}
