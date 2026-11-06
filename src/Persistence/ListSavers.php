<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

class ListSavers implements EntitySaverContainerInterface
{
    /**
     * @param array<string, EntitySaverInterface> $savers
     */
    public function __construct(
        private readonly array $savers
    ) {
    }

    public function getEntitySaverByName(string $name): EntitySaverInterface
    {
        if (!isset($this->savers[$name])) {
            throw new SaverNotConfiguredException($name);
        }

        return $this->savers[$name];
    }
}
