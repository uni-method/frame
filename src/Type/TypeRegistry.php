<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

final class TypeRegistry
{
    /** @var array<string, TypeDef> */
    private array $defs = [];

    /**
     * @param TypeDef[] $defs
     */
    public function __construct(array $defs = [])
    {
        foreach ($defs as $def) {
            $this->defs[strtolower($def->name)] = $def;
        }
    }

    public function find(string $name): ?TypeDef
    {
        return $this->defs[strtolower($name)] ?? null;
    }
}
