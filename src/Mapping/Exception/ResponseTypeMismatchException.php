<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use Throwable;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * A value obtained from an object while mapping the response does not match the schema:
 * the locator/saver/repository returned an object not of the model's class, the getter returned
 * a value of the wrong kind. A server error, not a client one.
 *
 * Значение, полученное из объекта при маппинге ответа, не соответствует схеме:
 * locator/saver/repository вернул объект не класса модели, геттер вернул
 * значение не того вида. Ошибка сервера, не клиента.
 */
final class ResponseTypeMismatchException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(
        string $ctx,
        string $name,
        string $expected,
        mixed $got,
        ?Throwable $prev = null,
    ) {
        $repr = is_object($got) ? get_debug_type($got) : var_export($got, true);
        parent::__construct(
            sprintf(
                "Response type mismatch in '%s' '%s': expected %s, got %s",
                $ctx,
                $name,
                $expected,
                $repr,
            ),
            0,
            $prev,
        );
    }
}
