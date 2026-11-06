<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The writer entity: attributes id, name; relation address → Address.
 *
 * Сущность writer: атрибуты id, name; связь address → Address.
 */
final class Writer
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?Address $address = null,
    ) {
    }
}
