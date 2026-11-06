<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class UnknownRelationException extends ValidatorException
{
    public function __construct(
        string $modelType,
        string $relationName,
        string $fullPath,
    ) {
        parent::__construct(
            sprintf(
                "Relation '%s' does not exist in model '%s' (path: '%s')",
                $relationName,
                $modelType,
                $fullPath,
            ),
        );
    }
}
