<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

class ListLocators implements EntityLocatorContainerInterface
{
    /**
     * @param array<string, EntityLocatorInterface> $locators
     */
    public function __construct(
        private readonly array $locators
    ) {
    }

    public function getEntityLocatorByName(string $name): EntityLocatorInterface
    {
        if (!isset($this->locators[$name])) {
            throw new LocatorNotConfiguredException($name);
        }

        return $this->locators[$name];
    }
}
