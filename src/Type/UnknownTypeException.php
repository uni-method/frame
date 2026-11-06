<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class UnknownTypeException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $type)
    {
        parent::__construct('Type not found: ' . $type);
    }
}
