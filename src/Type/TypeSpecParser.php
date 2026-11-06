<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

use DateTimeImmutable;

class TypeSpecParser
{
    /** @var array<string, TypeSpec> Parsed declarations (TypeSpec is immutable) */
    private array $cache = [];

    public function __construct(
        private readonly ?TypeRegistry $registry = null,
    ) {
    }

    public function parse(string $decl): TypeSpec
    {
        return $this->cache[$decl] ??= $this->doParse($decl);
    }

    private function doParse(string $decl): TypeSpec
    {
        $decl = trim($decl);

        // Normalize spaces around | to handle "string | null"
        //
        // Нормализуем пробелы вокруг |, чтобы обработать "string | null"
        $decl = preg_replace('/\s*\|\s*/', '|', $decl);

        // Check for nullable BEFORE checking for list to support "string[]|null"
        //
        // Проверяем nullable ДО проверки на list, чтобы поддержать "string[]|null"
        $nullable = str_contains($decl, '|null') || str_contains($decl, 'null|');
        $decl = str_replace(['|null', 'null|'], '', $decl);
        $decl = trim($decl, '| ');

        // Check for list AFTER nullable handling
        //
        // Проверяем list ПОСЛЕ обработки nullable
        $isList = str_ends_with($decl, '[]');
        if ($isList) {
            $decl = substr($decl, 0, -2);
        }

        $base = strtolower($decl);

        $spec = match ($base) {
            'string' => new TypeSpec(ValueKind::String, $nullable, $isList),
            'int', 'integer' => new TypeSpec(ValueKind::Int, $nullable, $isList),
            'float', 'double', 'real' => new TypeSpec(ValueKind::Float, $nullable, $isList),
            'bool', 'boolean' => new TypeSpec(ValueKind::Bool, $nullable, $isList),
            'datetime', '\datetimeimmutable', '\datetime' => new TypeSpec(
                ValueKind::DateTime,
                $nullable,
                $isList,
                phpClass: DateTimeImmutable::class,
            ),
            'uuid' => new TypeSpec(ValueKind::Uuid, $nullable, $isList),
            default => null,
        };

        if ($spec instanceof TypeSpec) {
            return $spec;
        }

        $def = $this->registry?->find($base);
        if ($def instanceof TypeDef) {
            return new TypeSpec($def->kind, $nullable, $isList, declaredName: $def->name);
        }

        throw new UnknownTypeException($base);
    }
}
