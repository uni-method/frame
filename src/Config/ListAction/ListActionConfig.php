<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

final readonly class ListActionConfig
{
    /**
     * @param class-string|null $class the root class that aggregates all classes that will then be built
     *     based on the rules
     * @param class-string|null $repository the repository class that must implement EntityCollectionInterface
     */
    public function __construct(
        public ?string $class = null,
        public ?string $repository = null,
        public ?PaginationRule $pagination = null,
        /** @var SortRule[] */
        public array $sort = [],
        /** @var FilterRule[] */
        public array $filters = [],
    ) {
    }
}
