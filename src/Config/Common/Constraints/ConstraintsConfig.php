<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Constraints;

final readonly class ConstraintsConfig
{
    /**
     * @param ConstraintsModel[] $models
     */
    public function __construct(
        public array $models = [],
    ) {
    }
}
