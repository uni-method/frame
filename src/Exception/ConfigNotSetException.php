<?php

declare(strict_types=1);

namespace UniMethod\Frame\Exception;

use LogicException;

/**
 * The service was called before it was given a Config (constructor or setConfig()).
 *
 * Сервис вызван до того, как ему передали Config (конструктор или setConfig()).
 */
final class ConfigNotSetException extends LogicException implements
    FrameConfigurationException
{
    public function __construct(string $service)
    {
        parent::__construct(sprintf(
            "%s has no Config: pass it to the constructor or call setConfig() at bootstrap.",
            $service,
        ));
    }
}
