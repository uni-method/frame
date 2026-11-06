<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

use UniMethod\Frame\Integration\Errors;

final class ActionNotFoundException extends ValidatorException
{
    public function __construct(string $actionType, string $modelType)
    {
        parent::__construct(
            sprintf(
                "Validator '%s' is not defined for model '%s'",
                $actionType,
                $modelType,
            ),
        );
    }

    /**
     * "Action/method is not allowed for the model" → 405 Method Not Allowed,
     * not 422: the request body itself has nothing to do with it.
     *
     * «Экшен/метод не разрешён для модели» → 405 Method Not Allowed,
     * а не 422: само тело запроса тут ни при чём.
     */
    public function getHttpErrorCode(): int
    {
        return Errors::METHOD_NOT_ALLOWED_ERROR_CODE;
    }
}
