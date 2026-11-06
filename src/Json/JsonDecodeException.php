<?php

declare(strict_types=1);

namespace UniMethod\Frame\Json;

use RuntimeException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * Failed to decode the request body JSON (the client sent broken JSON)
 * → 400 Bad Request.
 *
 * Не удалось декодировать JSON тела запроса (клиент прислал битый JSON)
 * → 400 Bad Request.
 */
final class JsonDecodeException extends RuntimeException implements
    FrameDomainException
{
    public function getHttpErrorCode(): int
    {
        return Errors::DEFAULT_ERROR_CODE;
    }
}
