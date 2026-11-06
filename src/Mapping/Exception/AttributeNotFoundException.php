<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class AttributeNotFoundException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $class, string $name)
    {
        parent::__construct(sprintf("No method/property '%s' in %s", $name, $class));
    }
}
