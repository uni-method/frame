<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

use Exception;
use UniMethod\Frame\Config\ModelLocatorResolver;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class DtoReferencedException extends Exception implements
    FrameConfigurationException
{
    public function __construct(string $modelType, string $path, string $mode)
    {
        parent::__construct(
            sprintf(
                "Model '%s' at path '%s' has no entity locator (DTO). Mode '%s' requires persistence. %s",
                $modelType,
                $path,
                $mode,
                ModelLocatorResolver::hint($modelType),
            ),
        );
    }
}
