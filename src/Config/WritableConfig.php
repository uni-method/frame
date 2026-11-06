<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

final readonly class WritableConfig
{
    public function __construct(
        public RelationWriteMode $mode,
        public ?AccessConfig $access = null,
    ) {
    }
}
