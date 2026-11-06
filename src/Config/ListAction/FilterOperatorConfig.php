<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

use UniMethod\Frame\Config\AccessConfig;

final readonly class FilterOperatorConfig
{
    public function __construct(
        public FilterOperator $operator,
        public ?AccessConfig $access = null,
    ) {
    }
}
