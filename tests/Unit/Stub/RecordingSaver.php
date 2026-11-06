<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

use UniMethod\Frame\Persistence\EntitySaverInterface;

/**
 * Saver: counts calls, returns the passed object or the given $returns.
 *
 * Сейвер: считает вызовы, возвращает переданный объект или заданный $returns.
 */
final class RecordingSaver implements EntitySaverInterface
{
    public int $saveCalls = 0;

    public function __construct(private readonly ?object $returns = null)
    {
    }

    public function save(object $objectToSave): object
    {
        $this->saveCalls++;

        return $this->returns ?? $objectToSave;
    }
}
