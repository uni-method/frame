<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mutator;

use UniMethod\Frame\Config\{ActionType, Model, PhaseType};

interface AttributeMutatorInterface
{
    /**
     * Applies the attribute transformation for a specific phase.
     *
     * Применяет трансформацию атрибута для конкретной фазы.
     */
    public function apply(
        PhaseType $phase,
        object $entity,
        Model $model,
        string $attribute,
        mixed $value,
        ActionType $actionType,
    ): mixed;
}
