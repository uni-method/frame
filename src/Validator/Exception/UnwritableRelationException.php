<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class UnwritableRelationException extends ValidatorException
{
    public function __construct(
        string $modelType,
        string $relationName,
        string $actionType,
    ) {
        parent::__construct(
            sprintf(
                "Relation '%s' for '%s' model is not writable for action '%s'. Check 'writable' field in action " .
                "configuration.",
                $relationName,
                $modelType,
                $actionType,
            ),
        );
    }
}
