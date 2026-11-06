<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

/**
 * External constraint check. An empty array — no violations;
 * a non-empty one — the framework turns it into ApiResult::errors().
 * Interrupting outcomes (403/404) — via a domain exception from the implementation.
 *
 * Внешняя проверка ограничений. Пустой массив — нарушений нет;
 * непустой — фреймворк оформляет его в ApiResult::errors().
 * Прерывающие исходы (403/404) — доменным исключением из реализации.
 */
interface ConstraintCheckerInterface
{
    /**
     * @return array<Error>
     */
    public function check(object $object, ConstraintContext $context): array;
}
