<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

/**
 * Default sort value
 *
 * Значение сортировки по умолчанию
 */
final readonly class DefaultSortValue
{
    public function __construct(
        public string $path,
        public SortDirection $direction,
    ) {
    }
}
