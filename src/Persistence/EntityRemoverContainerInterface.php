<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

interface EntityRemoverContainerInterface
{
    public function getEntityRemoverByName(string $name): EntityRemoverInterface;
}
