<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class ModelNotFoundException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $type)
    {
        parent::__construct('Model not found: ' . $type);
    }
}
