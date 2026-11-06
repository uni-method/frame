<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * Access to the requested filter operator is forbidden for the current user
 * → 403 Forbidden, or 401 Unauthorized if the user is not authenticated.
 * Thrown when checking access to the operators of a list action.
 *
 * Доступ к запрошенному оператору фильтра запрещён для текущего пользователя
 * → 403 Forbidden, либо 401 Unauthorized, если пользователь не аутентифицирован.
 * Бросается при проверке доступа к операторам list-действия.
 */
final class ForbiddenOperatorException extends RuntimeException implements
    FrameDomainException
{
    public function __construct(
        string $operator,
        string $filterPath,
        private readonly int $httpErrorCode = Errors::FORBIDDEN_ERROR_CODE,
    ) {
        parent::__construct(
            sprintf(
                "Access denied for operator '%s' on filter '%s'.",
                $operator,
                $filterPath,
            ),
        );
    }

    public function getHttpErrorCode(): int
    {
        return $this->httpErrorCode;
    }
}
