<?php

declare(strict_types=1);

namespace UniMethod\Frame\Json;

use InvalidArgumentException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * The request body JSON was parsed, but it is not an object → 400 Bad Request.
 *
 * JSON тела запроса распарсился, но это не объект → 400 Bad Request.
 */
final class InvalidJsonStructureException extends InvalidArgumentException implements FrameDomainException
{
    public function getHttpErrorCode(): int
    {
        return Errors::DEFAULT_ERROR_CODE;
    }
}
