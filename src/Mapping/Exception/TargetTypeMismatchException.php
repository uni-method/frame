<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * An object of a class other than the model's was passed to MappingToObject::map for Update:
 * an error of the calling code (the locator returned someone else's entity), not of the client.
 *
 * В MappingToObject::map для Update передан объект не того класса, что у
 * модели: ошибка вызывающего кода (локатор вернул чужую сущность), не клиента.
 */
final class TargetTypeMismatchException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $modelType, string $expectedClass, string $actualClass)
    {
        parent::__construct(
            sprintf(
                "Update target for model '%s' must be an instance of %s, %s given",
                $modelType,
                $expectedClass,
                $actualClass,
            ),
        );
    }
}
