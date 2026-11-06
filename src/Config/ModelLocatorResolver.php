<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

/**
 * The locator used to load the model by id — for references (`reference`) and
 * list filters by a whole relation. Order:
 *
 * 1. `impl.models.<model>.entityLocator`;
 * 2. `entityLocator` of the model's read action;
 * 3. `impl.defaultLocator`.
 *
 * Locators of other actions (update, delete) are not used: the result does not
 * depend on the order of actions in the config, and one model's locator is not
 * substituted for another.
 *
 * Локатор, которым модель грузится по id — для ссылок (`reference`) и
 * фильтров list по связи целиком. Порядок:
 *
 * 1. `impl.models.<модель>.entityLocator`;
 * 2. `entityLocator` read-действия модели;
 * 3. `impl.defaultLocator`.
 *
 * Локатор других действий (update, delete) не используется: результат не
 * зависит от порядка действий в конфиге, а локатор одной модели не
 * подставляется для другой.
 */
final class ModelLocatorResolver
{
    /**
     * @param Model[] $models
     * @param Action[] $actions
     */
    public static function resolve(
        array $models,
        array $actions,
        ?string $defaultLocator,
        string $modelType,
    ): ?string {
        foreach ($models as $model) {
            if ($model->type === $modelType && self::isAlias($model->entityLocator)) {
                return $model->entityLocator;
            }
        }

        foreach ($actions as $action) {
            if (
                $action->model === $modelType &&
                $action->type === ActionType::Read &&
                self::isAlias($action->entityLocator)
            ) {
                return $action->entityLocator;
            }
        }

        return self::isAlias($defaultLocator) ? $defaultLocator : null;
    }

    /**
     * Hint for error messages: how to declare the model's locator.
     *
     * Подсказка для сообщений об ошибке: как объявить локатор модели.
     */
    public static function hint(string $modelType): string
    {
        return sprintf(
            "Set impl.models[%s].entityLocator, a read action with entityLocator or impl.defaultLocator",
            $modelType,
        );
    }

    /**
     * @phpstan-assert-if-true non-empty-string $alias
     */
    private static function isAlias(?string $alias): bool
    {
        return $alias !== null && trim($alias) !== "";
    }
}
