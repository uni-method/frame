<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

use UniMethod\Frame\Tests\Unit\Stub\ChildNode;

/**
 * A collection with only `add`: on create it is filled, on update it cannot be replaced.
 *
 * Коллекция только с `add`: на create наполняется, на update заменить нельзя.
 */
final class AddOnlyBox
{
    /** @var list<ChildNode> */
    private array $items = [];

    public function __construct(public ?string $id = null)
    {
    }

    public function addItem(ChildNode $child): void
    {
        $this->items[] = $child;
    }

    /** @return list<ChildNode> */
    public function getItems(): array
    {
        return $this->items;
    }
}
