<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

interface EntitySaverContainerInterface
{
    public function getEntitySaverByName(string $name): EntitySaverInterface;
}
