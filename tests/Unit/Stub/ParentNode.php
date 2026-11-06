<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * An entity with relations of all four types to ChildNode.
 *
 * Сущность со связями всех четырёх типов на ChildNode.
 */
final class ParentNode
{
    /**
     * @param list<ChildNode> $many
     * @param list<ChildNode> $tags
     */
    public function __construct(
        public ?string $id = null,
        public ?ChildNode $single = null,
        public array $many = [],
        public ?ChildNode $owner = null,
        public array $tags = [],
    ) {
    }
}
