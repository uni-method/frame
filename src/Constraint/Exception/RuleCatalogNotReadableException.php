<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class RuleCatalogNotReadableException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $path)
    {
        parent::__construct('Cannot read rule catalog: ' . $path);
    }
}
