<?php

declare(strict_types=1);

namespace UniMethod\Frame\Exception;

use LogicException;

/**
 * The service config is set once — in the constructor or by the first setConfig().
 * Setting it again under load (a long-lived worker) would rebuild the
 * service's indexes in the middle of someone else's request, so it is forbidden.
 *
 * Конфиг сервиса задаётся один раз — в конструкторе или первым setConfig().
 * Повторная установка под нагрузкой (долгоживущий воркер) перестраивала бы
 * индексы сервиса посреди чужого запроса, поэтому запрещена.
 */
final class ConfigAlreadySetException extends LogicException implements
    FrameConfigurationException
{
    public function __construct(string $service)
    {
        parent::__construct(sprintf(
            "%s already has a Config; it can be set only once (constructor or first setConfig()).",
            $service,
        ));
    }
}
