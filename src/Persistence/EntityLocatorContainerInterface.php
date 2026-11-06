<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

/**
 * The idea is that here we create and put into a hash all bindings from the Config->locators config
 * so that we can quickly retrieve them for a given type later
 *
 * Идея в том, что тут мы создаем и кладем в хеш все завязки из конфига Config->locators
 * чтобы потом оперативно доставать для того или иного типа
 */
interface EntityLocatorContainerInterface
{
    public function getEntityLocatorByName(string $name): EntityLocatorInterface;
}
