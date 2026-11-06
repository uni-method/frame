<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config\Stub;

use UniMethod\Frame\Persistence\EntityLocatorInterface;

final class TestEntityLocator implements EntityLocatorInterface
{
    public function find(
        string $className,
        int|string $id,
        array $include = [],
        ?object $object = null,
    ): object|null {
        return null;
    }
}
