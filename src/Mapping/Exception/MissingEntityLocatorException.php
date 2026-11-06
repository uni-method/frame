<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * The exception is thrown when the model has no configured entityLocator
 *
 * Исключение выбрасывается когда модель не имеет настроенного entityLocator
 */
final class MissingEntityLocatorException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(
        string $modelType,
        string $context = 'Update mode',
        ?string $hint = null,
    ) {
        parent::__construct(
            sprintf(
                "%s for model '%s' requires entityLocator configuration",
                $context,
                $modelType
            ) . ($hint !== null ? ". " . $hint : "")
        );
    }
}
