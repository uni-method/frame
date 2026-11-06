<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use Throwable;
use UniMethod\Frame\Exception\FrameConfigurationException;

final class EntityInstantiationException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(
        string $entityClass,
        string $modelType,
        string $reason,
        ?Throwable $previous = null
    ) {
        parent::__construct(
            sprintf(
                "Failed to instantiate entity '%s' (model type: '%s'): %s",
                $entityClass,
                $modelType,
                $reason
            ),
            0,
            $previous
        );
    }
}
