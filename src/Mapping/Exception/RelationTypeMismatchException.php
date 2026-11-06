<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use Exception;
use Throwable;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

final class RelationTypeMismatchException extends Exception implements
    FrameDomainException
{
    public function __construct(
        public readonly string $modelType,
        public readonly string $relationName,
        public readonly string $expectedType,
        public readonly string $actualType,
        ?Throwable $previous = null,
    ) {
        $message = sprintf(
            "Type mismatch in model '%s', relation '%s': expected %s, got %s",
            $modelType,
            $relationName,
            $expectedType,
            $actualType,
        );

        parent::__construct($message, 0, $previous);
    }

    public function getHttpErrorCode(): int
    {
        return Errors::ENTITY_VALIDATION_ERROR_CODE;
    }
}
