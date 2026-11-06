<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

final class InvalidDataStructureException extends ValidatorException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
