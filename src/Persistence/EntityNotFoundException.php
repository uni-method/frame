<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

use RuntimeException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

final class EntityNotFoundException extends RuntimeException implements
    FrameDomainException
{
    public function __construct(string $className, int|string $id)
    {
        parent::__construct(
            sprintf("Entity '%s' with id '%s' not found.", $className, $id),
        );
    }

    public function getHttpErrorCode(): int
    {
        return Errors::NOT_FOUND_ERROR_CODE;
    }
}
