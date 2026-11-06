<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

final readonly class TypeSpec
{
    public function __construct(
        public ValueKind $kind,
        public bool $nullable = false,
        public bool $isList = false,
        // for object types (DateTime, third-party classes)
        //
        // для объектных типов (DateTime, сторонние классы)
        public ?string $phpClass = null,
        // type name from the registry (enum/money); null — built-in
        //
        // имя типа из реестра (enum/money); null — встроенный
        public ?string $declaredName = null
    ) {
    }
}
