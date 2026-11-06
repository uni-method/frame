<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

final readonly class ConstraintViolation
{
    public function __construct(
        public string $path,
        public RuleType $rule,
        public string $message,
    ) {
    }
}
