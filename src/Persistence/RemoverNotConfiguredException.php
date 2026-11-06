<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class RemoverNotConfiguredException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $modelType)
    {
        parent::__construct(sprintf("Remover is not configured for model '%s'.", $modelType));
    }
}
