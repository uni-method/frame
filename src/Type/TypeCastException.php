<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

use InvalidArgumentException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

final class TypeCastException extends InvalidArgumentException implements
    FrameDomainException
{
    public function __construct(string $expectedType, mixed $actualValue)
    {
        $actualType = get_debug_type($actualValue);
        parent::__construct(
            sprintf(
                "Cannot cast value of type %s to %s",
                $actualType,
                $expectedType,
            ),
        );
    }

    public function getHttpErrorCode(): int
    {
        return Errors::ENTITY_VALIDATION_ERROR_CODE;
    }
}
