<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class UnknownPropertyException extends ValidatorException
{
    public function __construct(string $modelType, string $propertyName)
    {
        parent::__construct(
            sprintf(
                "Unknown property '%s' for model '%s'. Property is neither an attribute nor a relation.",
                $propertyName,
                $modelType,
            ),
        );
    }
}
