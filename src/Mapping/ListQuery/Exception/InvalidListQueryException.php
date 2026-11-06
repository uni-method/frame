<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery\Exception;

use RuntimeException;
use Throwable;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * Invalid list request parameters (query string): a malformed filter value,
 * an operator requires an array, a non-nullable field received an empty value, etc.
 * This is a malformed request → 400 Bad Request, not 422 (the entity body has nothing to do with it).
 *
 * Невалидные параметры list-запроса (query string): кривое значение фильтра,
 * оператор требует массив, non-nullable поле получило пустое значение и т.п.
 * Это malformed request → 400 Bad Request, а не 422 (тело сущности тут ни при чём).
 */
final class InvalidListQueryException extends RuntimeException implements
    FrameDomainException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpErrorCode(): int
    {
        return Errors::DEFAULT_ERROR_CODE;
    }
}
