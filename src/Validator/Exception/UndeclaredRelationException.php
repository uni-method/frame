<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

/**
 * A relation is present in the create/update body but not declared in `?included=`.
 * Input contract: only what the client declared is written; undeclared
 * relations are an error, not a silent skip.
 *
 * Связь присутствует в теле create/update, но не задекларирована в `?included=`.
 * Контракт входа: пишется только то, что клиент заявил; недекларированные
 * связи — ошибка, а не тихий пропуск.
 */
final class UndeclaredRelationException extends ValidatorException
{
    public function __construct(string $modelType, string $relationPath)
    {
        parent::__construct(
            sprintf(
                "Relation '%s' of model '%s' is present in the request body but not declared in 'included'.",
                $relationPath,
                $modelType,
            ),
        );
    }
}
