<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

use InvalidArgumentException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * The input value is not suitable for casting by the type structure: null was passed
 * for a non-nullable one, or a non-iterable was passed for a list type. This is invalid
 * request data → 422. Extends InvalidArgumentException to remain
 * compatible with Caster consumers that catch InvalidArgumentException.
 *
 * Входное значение не подходит для приведения по структуре типа: null передан
 * для non-nullable, либо для list-типа передано не-iterable. Это невалидные
 * данные запроса → 422. Наследует InvalidArgumentException, чтобы оставаться
 * совместимым с потребителями Caster, которые ловят InvalidArgumentException.
 */
final class InvalidCastInputException extends InvalidArgumentException implements FrameDomainException
{
    public function getHttpErrorCode(): int
    {
        return Errors::ENTITY_VALIDATION_ERROR_CODE;
    }
}
