<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration\Exception;

use RuntimeException;
use UniMethod\Frame\Config\Action;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * EntitySaverInterface::save() returned a different instance than it received.
 * A violation of the integration contract, not a domain error: flies to a 500.
 *
 * EntitySaverInterface::save() вернул не тот экземпляр, что получил.
 * Нарушение контракта интеграции, не доменная ошибка: улетает в 500.
 */
final class SaverContractViolationException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(Action $action, string $saverAlias)
    {
        parent::__construct(sprintf(
            "Saver '%s' of action '%s' on model '%s' must return the same instance it was given.",
            $saverAlias,
            $action->type->value,
            $action->model,
        ));
    }
}
