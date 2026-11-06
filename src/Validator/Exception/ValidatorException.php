<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

use Exception;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

abstract class ValidatorException extends Exception implements
    FrameDomainException
{
    /**
     * Validator errors by default relate to an invalid request body/data
     * structure → 422. Subclasses that have different semantics (for example
     * "method not allowed") override this method.
     *
     * Валидаторные ошибки по умолчанию относятся к невалидному телу/структуре
     * данных запроса → 422. Подклассы, у которых другая семантика (например
     * «метод не разрешён»), переопределяют этот метод.
     */
    public function getHttpErrorCode(): int
    {
        return Errors::ENTITY_VALIDATION_ERROR_CODE;
    }
}
