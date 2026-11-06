<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mutator;

use UniMethod\Frame\Config\{ActionType, Model, PhaseType};

interface RelationMutatorInterface
{
    /**
     * Applies the relation transformation for a specific phase.
     *
     * Применяет трансформацию связи для конкретной фазы.
     */
    public function apply(
        PhaseType $phase,
        object $entity,
        Model $model,
        string $relation,
        mixed $value,
        ActionType $actionType,
    ): mixed;
}
