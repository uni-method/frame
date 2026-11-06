<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The user entity: public properties and accessors (including one with a Cyrillic name).
 *
 * Сущность user: публичные свойства и аксессоры (в т.ч. с кириллическим именем).
 */
final class User
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function имя(): ?string // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    {
        return $this->name;
    }
}
