<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration\Exception;

use RuntimeException;
use UniMethod\Frame\Config\Action;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * The action has no locator/saver/remover alias set. A misconfiguration, not a domain
 * error: it does not implement FrameDomainException and flies to a 500.
 *
 * У действия не задан алиас locator/saver/remover. Мисконфиг, не доменная
 * ошибка: не реализует FrameDomainException и улетает в 500.
 */
final class ActionAliasNotConfiguredException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(Action $action, string $field)
    {
        parent::__construct(sprintf(
            "Action '%s' on model '%s' has no '%s' configured.",
            $action->type->value,
            $action->model,
            $field,
        ));
    }
}
