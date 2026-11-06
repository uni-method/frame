<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

use UniMethod\Frame\Config\ActionType;

final readonly class ConstraintContext
{
    /**
     * @param string          $modelType model type from schema.models
     * @param string|int|null $id        id from the request (read/update/delete) or the reference id (reference);
     *                                   null for create/list
     * @param object|null     $original  snapshot of the object before input is applied; only update in the AfterInput
     *                                   phase.
     *                                   A shallow clone of the entity: scalars and value objects (DateTime)
     *                                   are reliable, related objects are the same instances as in the
     *                                   current object (compare them by id, not by fields).
     *                                   Collections — the same array/Collection as in the current one
     */
    public function __construct(
        public string $modelType,
        public ActionType $actionType,
        public ConstraintPhase $phase,
        public ?UserInterface $user = null,
        public string|int|null $id = null,
        public ?object $original = null,
    ) {
    }

    public static function afterLocate(
        string $modelType,
        ActionType $actionType,
        string|int $id,
        ?UserInterface $user = null,
    ): self {
        return new self($modelType, $actionType, ConstraintPhase::AfterLocate, $user, $id);
    }

    public static function afterInput(
        string $modelType,
        ActionType $actionType,
        ?UserInterface $user = null,
        string|int|null $id = null,
        ?object $original = null,
    ): self {
        return new self($modelType, $actionType, ConstraintPhase::AfterInput, $user, $id, $original);
    }

    public static function listQuery(string $modelType, ?UserInterface $user = null): self
    {
        return new self($modelType, ActionType::List, ConstraintPhase::ListQuery, $user);
    }
}
