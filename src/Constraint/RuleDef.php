<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

use UniMethod\Frame\Type\ValueKind;

final readonly class RuleDef
{
    /**
     * @param ValueKind[]|null $kinds null — applicable to any kind ("*")
     * @param array<string, string> $params Parameter specification; the "?" suffix — optional
     */
    public function __construct(
        public RuleType $type,
        public RuleScope $scope,
        public ?array $kinds,
        public bool $relations,
        public bool $requiresNullable = false,
        public array $params = [],
    ) {
    }
}
