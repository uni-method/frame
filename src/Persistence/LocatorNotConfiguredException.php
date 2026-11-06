<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class LocatorNotConfiguredException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $modelType)
    {
        parent::__construct(sprintf("Entity locator is not configured for model '%s'.", $modelType));
    }
}
