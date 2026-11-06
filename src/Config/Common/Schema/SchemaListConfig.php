<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

use UniMethod\Frame\Config\ListAction\{FilterRule, PaginationRule, SortRule};

final readonly class SchemaListConfig
{
    /**
     * @param SortRule[] $sort
     * @param FilterRule[] $filters
     */
    public function __construct(
        public ?PaginationRule $pagination = null,
        public array $sort = [],
        public array $filters = [],
    ) {
    }
}
