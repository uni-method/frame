<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class RelationModeNotAllowedException extends ValidatorException
{
    public function __construct(
        string $modelType,
        string $path,
        string $mode,
        string $action,
    ) {
        parent::__construct(
            sprintf(
                "Relation '%s' in model '%s' does not allow mode '%s' for action '%s'.",
                $path,
                $modelType,
                $mode,
                $action,
            ),
        );
    }
}
