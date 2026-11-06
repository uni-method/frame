<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The target entity of ParentNode's relations.
 *
 * Целевая сущность связей ParentNode.
 */
final class ChildNode
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
    ) {
    }
}
