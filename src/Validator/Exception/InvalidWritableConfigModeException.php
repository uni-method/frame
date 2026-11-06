<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

use Exception;
use Throwable;
use UniMethod\Frame\Config\RelationWriteMode;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class InvalidWritableConfigModeException extends Exception implements
    FrameConfigurationException
{
    public function __construct(
        string $path,
        mixed $value,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                "Writable mode for path '%s' must be %s, %s given.",
                $path,
                RelationWriteMode::class,
                get_debug_type($value),
            ),
            0,
            $previous,
        );
    }
}
