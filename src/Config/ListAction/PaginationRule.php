<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

final readonly class PaginationRule
{
    public const DEFAULT_MAX_SIZE = 100;

    public function __construct(
        public ?int $maxSize = self::DEFAULT_MAX_SIZE,
        public int $defaultSize = 20,
    ) {
    }
}
