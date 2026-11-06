<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * The exception is thrown when the object ID is not passed for Update
 *
 * Исключение выбрасывается когда для Update не передан ID объекта
 */
final class UpdateRequiresIdException extends RuntimeException implements
    FrameDomainException
{
    public function __construct(string $modelType, string $pkField)
    {
        parent::__construct(
            sprintf(
                "Update mode for model '%s' requires either rootId parameter or '%s' field in data",
                $modelType,
                $pkField,
            ),
        );
    }

    public function getHttpErrorCode(): int
    {
        return Errors::ENTITY_VALIDATION_ERROR_CODE;
    }
}
