<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

class ListRemovers implements EntityRemoverContainerInterface
{
    /**
     * @param array<string, EntityRemoverInterface> $removers
     */
    public function __construct(
        private readonly array $removers
    ) {
    }

    public function getEntityRemoverByName(string $name): EntityRemoverInterface
    {
        if (!isset($this->removers[$name])) {
            throw new RemoverNotConfiguredException($name);
        }

        return $this->removers[$name];
    }
}
