<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The address entity: attributes id, city.
 *
 * Сущность address: атрибуты id, city.
 */
final class Address
{
    public function __construct(
        public ?string $id = null,
        public ?string $city = null,
    ) {
    }
}
