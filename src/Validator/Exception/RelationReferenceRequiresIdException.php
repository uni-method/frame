<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class RelationReferenceRequiresIdException extends ValidatorException
{
    public function __construct(string $path)
    {
        parent::__construct(
            sprintf(
                "Reference '%s' requires a non-null 'id' field.",
                $path,
            ),
        );
    }
}
