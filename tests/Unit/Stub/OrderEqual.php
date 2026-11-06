<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * An operator DTO with a required constructor argument.
 *
 * Операторный DTO с обязательным аргументом конструктора.
 */
final class OrderEqual
{
    public function __construct(
        private readonly string $required,
        public ?string $name = null,
    ) {
    }

    public function getRequired(): string
    {
        return $this->required;
    }
}
