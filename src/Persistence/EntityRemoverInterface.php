<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

interface EntityRemoverInterface
{
    public function remove(object $objectToRemove): void;
}
