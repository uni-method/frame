<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

use UniMethod\Frame\Config\{AccessConfig, Action};

/**
 * Responsible for providing the current authorized user
 * and checking their access rights to actions.
 *
 * Отвечает за предоставление текущего авторизованного пользователя
 * и проверку его прав доступа к действиям (actions).
 */
interface UserProviderInterface
{
    /**
     * Returns the current authorized user.
     * If the user is not authorized — return null.
     *
     * Возвращает текущего авторизованного пользователя.
     * Если пользователь не авторизован — вернуть null.
     */
    public function getCurrentUser(): ?UserInterface;

    /**
     * Checks whether the current user has access to the given action.
     *
     * Проверяет, имеет ли текущий пользователь доступ к указанному действию.
     *
     * @param Action $action Action config (including accessConfig)
     */
    public function canAccessAction(Action $action): bool;

    public function canAccess(AccessConfig $config): bool;

    /**
     * Checks whether the user has at least one of the given roles,
     * taking role inheritance into account.
     *
     * Проверяет, имеет ли пользователь хотя бы одну из указанных ролей,
     * с учётом наследования ролей.
     *
     * @param string[] $requiredRoles
     */
    public function userHasAnyRole(
        UserInterface $user,
        array $requiredRoles,
    ): bool;
}
