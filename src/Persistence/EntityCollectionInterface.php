<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

interface EntityCollectionInterface
{
    /**
     * @param list<string> $included List of relation paths for eager loading (for example: ['author', 'tags.group']),
     *                               the same flat representation that EntityLocatorInterface::find receives
     * @return list<object>
     */
    public function list(
        object|null $filter = null,
        object|null $sort = null,
        object|null $pagination = null,
        array $included = [],
    ): array;

    public function countForList(object|null $filter = null): int;
}
