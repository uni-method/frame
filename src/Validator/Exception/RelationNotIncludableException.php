<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class RelationNotIncludableException extends ValidatorException
{
    public function __construct(
        string $modelType,
        string $relationPath,
        string $actionType,
    ) {
        parent::__construct(
            sprintf(
                "Relation '%s' in model '%s' is not allowed for inclusion in action '%s'. Check 'include' field in " .
                "action configuration.",
                $relationPath,
                $modelType,
                $actionType,
            ),
        );
    }
}
