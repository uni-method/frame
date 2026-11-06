<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class RelationUpdateRequiresIdException extends ValidatorException
{
    public function __construct(string $path)
    {
        parent::__construct(
            sprintf(
                "Relation '%s' with mode 'update' requires an 'id' field.",
                $path,
            ),
        );
    }
}
