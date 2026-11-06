<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

use UniMethod\Frame\Config\{ActionType, Model, PhaseType};
use UniMethod\Frame\Mutator\AttributeMutatorInterface;

/**
 * Mutator: trim for strings.
 *
 * Мутатор: trim для строк.
 */
final class TrimMutator implements AttributeMutatorInterface
{
    public function apply(
        PhaseType $phase,
        object $entity,
        Model $model,
        string $attribute,
        mixed $value,
        ActionType $actionType,
    ): mixed {
        return is_string($value) ? trim($value) : $value;
    }
}
