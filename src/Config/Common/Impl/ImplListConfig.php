<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Impl;

final readonly class ImplListConfig
{
    /**
     * @param class-string|null $class PHP class of the aggregating request (ListRequest DTO)
     * @param class-string|null $repository PHP class of the repository implementing EntityCollectionInterface
     */
    public function __construct(
        public ?string $class = null,
        public ?string $repository = null,
    ) {
    }
}
