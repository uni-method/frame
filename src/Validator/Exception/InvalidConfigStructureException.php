<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

use Exception;
use Throwable;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * A structural error of the CONFIG (not of the request). Thrown at the config loading stage
 * (bootstrap, before ApiService::execute). Category — FrameConfigurationException:
 * a fatal configuration error, 500.
 *
 * Структурная ошибка КОНФИГА (не запроса). Бросается на этапе загрузки конфига
 * (bootstrap, до ApiService::execute). Категория — FrameConfigurationException:
 * фатальная конфигурационная ошибка, 500.
 */
final class InvalidConfigStructureException extends Exception implements
    FrameConfigurationException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
