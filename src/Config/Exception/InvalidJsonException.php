<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Exception;

use Exception;
use Throwable;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class InvalidJsonException extends Exception implements
    FrameConfigurationException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
