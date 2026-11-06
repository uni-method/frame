<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

use UniMethod\Frame\Config\{ActionType, Model, PhaseType};
use UniMethod\Frame\Mutator\AttributeMutatorInterface;

/**
 * A non-idempotent mutator: appends "!" and counts calls.
 *
 * Неидемпотентный мутатор: дописывает "!" и считает вызовы.
 */
final class SuffixMutator implements AttributeMutatorInterface
{
    public int $calls = 0;

    public function apply(
        PhaseType $phase,
        object $entity,
        Model $model,
        string $attribute,
        mixed $value,
        ActionType $actionType,
    ): mixed {
        $this->calls++;

        return $value . "!";
    }
}
