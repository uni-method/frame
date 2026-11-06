<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping;

abstract class TouchableDto
{
    /** @var array<string, true> */
    private array $touched = [];

    public function markAsSet(string $name): void
    {
        $this->touched[$name] = true;
    }

    public function isSet(string $name): bool
    {
        return isset($this->touched[$name]);
    }
}
