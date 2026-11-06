<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

use DateTimeImmutable;

/**
 * The item entity: int/float/datetime attributes.
 *
 * Сущность item: атрибуты int/float/datetime.
 */
final class Item
{
    public function __construct(
        public ?string $id = null,
        public ?int $qty = null,
        public ?float $price = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {
    }
}
