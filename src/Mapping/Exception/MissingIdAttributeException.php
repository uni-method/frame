<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * The model has no `id` attribute, but it is used as a reference target (reference):
 * without a primary key the reference cannot be resolved. For configs from the loader this is
 * impossible (ConfigValidator::validateModelIds); the exception covers manual
 * assembly of Config.
 *
 * У модели нет атрибута `id`, а она используется как цель ссылки (reference):
 * без первичного ключа ссылку не разрешить. Для конфигов из загрузчика это
 * невозможно (ConfigValidator::validateModelIds), исключение закрывает ручную
 * сборку Config.
 */
final class MissingIdAttributeException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $modelType, string $context)
    {
        parent::__construct(sprintf(
            "Model '%s' has no 'id' attribute but is used as a reference target (%s).",
            $modelType,
            $context,
        ));
    }
}
