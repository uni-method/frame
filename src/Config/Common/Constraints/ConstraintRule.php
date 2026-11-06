<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Constraints;

use UniMethod\Frame\Constraint\RuleType;

final readonly class ConstraintRule
{
    /**
     * @param RuleType $type Rule type from the catalog
     * @param array<string, mixed> $params Rule parameters (min, max, pattern, values, value)
     */
    public function __construct(
        public RuleType $type,
        public array $params = [],
    ) {
    }
}
