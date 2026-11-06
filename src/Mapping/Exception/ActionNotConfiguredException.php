<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * The action is missing from the config passed to the component via setConfig.
 * ApiService finds the action before calling the component, so here this is
 * a mismatch between configs, not a client error.
 *
 * Действие отсутствует в конфиге, переданном компоненту через setConfig.
 * ApiService находит действие до вызова компонента, поэтому здесь это
 * рассогласование конфигов, а не ошибка клиента.
 */
final class ActionNotConfiguredException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $actionType, string $modelType)
    {
        parent::__construct(
            sprintf(
                "Action '%s' is not configured for model '%s'",
                $actionType,
                $modelType,
            ),
        );
    }
}
