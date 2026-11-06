<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * Exception: the setter is unavailable or invalid
 *
 * Исключение: setter недоступен или некорректен
 */
final class SetterNotCallableException extends RuntimeException implements
    FrameConfigurationException
{
}
