<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

/**
 * Interface that the user model
 * marked as `auth:user` in the config must implement.
 *
 * Интерфейс, который должна реализовывать модель пользователя,
 * помеченная как `auth:user` в конфиге.
 */
interface UserInterface
{
    /**
     * Unique identifier of the user within the system.
     * May be an ID, UUID or login — depends on the implementation.
     *
     * Уникальный идентификатор пользователя в рамках системы.
     * Может быть ID, UUID или логин — зависит от реализации.
     */
    public function getUserIdentifier(): string|int;

    /**
     * Returns the list of the user's roles.
     *
     * Возвращает список ролей пользователя.
     *
     * @return string[]
     */
    public function getRoles(): array;

    /**
     * Checks whether the user has the given role.
     *
     * Проверяет, есть ли у пользователя указанная роль.
     */
    public function hasRole(string $role): bool;
}
