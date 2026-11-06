<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class SaverNotConfiguredException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $modelType)
    {
        parent::__construct(sprintf("Saver is not configured for model '%s'.", $modelType));
    }
}
