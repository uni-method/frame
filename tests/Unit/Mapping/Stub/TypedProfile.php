<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

/**
 * An entity with a typed property without an initializer — may be uninitialized.
 *
 * Сущность с типизированным свойством без инициализатора — может быть не инициализировано.
 */
final class TypedProfile
{
    public string $bio;

    public function __construct(public ?string $id = null)
    {
    }
}
