<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

use Exception;
use Throwable;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class MissingRequiredFieldException extends Exception implements
    FrameConfigurationException
{
    public function __construct(
        public readonly string $field,
        public readonly string $path,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf("Missing required field '%s' in %s", $field, $path),
            0,
            $previous,
        );
    }
}
