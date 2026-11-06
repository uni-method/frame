<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use Throwable;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

final class TypeMismatchException extends RuntimeException implements
    FrameDomainException
{
    public function __construct(
        string $ctx,
        string $name,
        string $expected,
        mixed $got,
        ?Throwable $prev = null,
    ) {
        $repr = is_object($got) ? get_debug_type($got) : var_export($got, true);
        parent::__construct(
            sprintf(
                "Type mismatch in '%s' '%s': expected %s, got %s",
                $ctx,
                $name,
                $expected,
                $repr,
            ),
            0,
            $prev,
        );
    }

    public function getHttpErrorCode(): int
    {
        return Errors::ENTITY_VALIDATION_ERROR_CODE;
    }
}
