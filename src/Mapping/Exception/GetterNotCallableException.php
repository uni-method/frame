<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * Exception: the getter is unavailable or invalid
 *
 * Исключение: getter недоступен или некорректен
 */
final class GetterNotCallableException extends RuntimeException implements
    FrameConfigurationException
{
}
