<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator\Exception;

/**
 * A path is declared in `?included=` on create/update but is missing from the body
 * (or a nested path is declared under a relation that was passed null).
 * A declaration is an obligation, not a permission.
 *
 * Путь задекларирован в `?included=` на create/update, но в теле отсутствует
 * (или задекларирован вложенный путь под связью, которой передан null).
 * Декларация — обязательство, а не разрешение.
 */
final class DeclaredRelationMissingException extends ValidatorException
{
    public function __construct(string $modelType, string $relationPath)
    {
        parent::__construct(
            sprintf(
                "Relation '%s' of model '%s' is declared in 'included' but missing in the request body.",
                $relationPath,
                $modelType,
            ),
        );
    }
}
