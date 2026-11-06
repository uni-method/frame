<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config\Stub;

use UniMethod\Frame\Persistence\EntityRemoverInterface;

final class TestEntityRemover implements EntityRemoverInterface
{
    public function remove(object $objectToRemove): void
    {
    }
}
