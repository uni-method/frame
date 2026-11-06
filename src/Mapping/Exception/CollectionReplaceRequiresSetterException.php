<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * On update a collection (`*ToMany`) is replaced entirely (PATCH semantics), which
 * requires a setter. A relation with `setter: false` and only `add` cannot be written on update —
 * appending without removal is not a replacement.
 *
 * На update коллекция (`*ToMany`) заменяется целиком (PATCH-семантика), для
 * этого нужен setter. Связь с `setter: false` и одним `add` на update писать
 * нельзя — дописывание без удаления не является заменой.
 */
final class CollectionReplaceRequiresSetterException extends RuntimeException implements
    FrameConfigurationException
{
    public function __construct(string $modelType, string $relationName)
    {
        parent::__construct(
            sprintf(
                "Collection relation '%s' of model '%s' is written on update but has no setter: update replaces the " .
                "whole collection, 'add' alone cannot do that",
                $relationName,
                $modelType,
            ),
        );
    }
}
