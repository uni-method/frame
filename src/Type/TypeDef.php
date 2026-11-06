<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

use UniMethod\Frame\Config\Common\Constraints\ConstraintRule;

final readonly class TypeDef
{
    /**
     * @param string $name Type name — what is written in the attribute's type spec
     * @param ValueKind $kind Stored scalar kind
     * @param array<string|int>|null $enum Allowed values; null — not an enum
     * @param ConstraintRule[] $rules Implicit rules of the type (always checked)
     */
    public function __construct(
        public string $name,
        public ValueKind $kind,
        public ?array $enum = null,
        public array $rules = [],
    ) {
    }
}
