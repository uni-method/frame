<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config\Stub;

use UniMethod\Frame\Persistence\EntitySaverInterface;

final class TestEntitySaver implements EntitySaverInterface
{
    public function save(object $objectToSave): object
    {
        return $objectToSave;
    }
}
