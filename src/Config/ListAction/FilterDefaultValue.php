<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

final readonly class FilterDefaultValue
{
    public function __construct(
        public FilterOperator $operator,
        public mixed $value,
    ) {
    }
}
