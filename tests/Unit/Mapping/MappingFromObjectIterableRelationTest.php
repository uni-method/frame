<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{ActionType, Attribute, Config, Model, Relation};
use UniMethod\Frame\Mapping\Exception\ResponseTypeMismatchException;
use UniMethod\Frame\Mapping\MappingFromObject;
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestIterableRelItem,
    TestIterableRelOwner,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * The value of a *ToMany relation is read as an iterable: the entity may return
 * a Doctrine Collection (Traversable) from the getter, a wrapper like
 * getItemsAsArray(): array is not needed for this.
 *
 * Значение *ToMany-связи читается как iterable: сущность вправе отдавать
 * Doctrine Collection (Traversable) из геттера, обёртка вида
 * getItemsAsArray(): array для этого не нужна.
 */
final class MappingFromObjectIterableRelationTest extends TestCase
{
    private MappingFromObject $mapper;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestIterableRelOwner",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestIterableRelItem
                {
                    public function __construct(public string $id)
                    {
                    }
                }

                /**
                 * The simplest analog of Doctrine\Common\Collections\Collection
                 *
                 * Простейший аналог Doctrine\Common\Collections\Collection
                 */
                class TestIterableRelCollection implements \IteratorAggregate
                {
                    /** @param list<TestIterableRelItem> $items */
                    public function __construct(private array $items = [])
                    {
                    }

                    public function getIterator(): \Traversable
                    {
                        return new \ArrayIterator($this->items);
                    }
                }

                class TestIterableRelOwner
                {
                    private TestIterableRelCollection $items;

                    private mixed $broken = null;

                    public function __construct(
                        public string $id,
                        array $items = [],
                        mixed $broken = null,
                    ) {
                        $this->items = new TestIterableRelCollection($items);
                        $this->broken = $broken;
                    }

                    public function getItems(): TestIterableRelCollection
                    {
                        return $this->items;
                    }

                    public function getBroken(): mixed
                    {
                        return $this->broken;
                    }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );

        $this->mapper->setConfig(
            new Config(
                models: [
                    new Model(
                        class: TestIterableRelOwner::class,
                        type: "iterableOwner",
                        attributes: [new Attribute(name: "id", type: "string")],
                        relations: [
                            new Relation(
                                name: "items",
                                type: Relation::ONE_TO_MANY,
                                targetModel: "iterableItem",
                                getter: "getItems",
                            ),
                            new Relation(
                                name: "broken",
                                type: Relation::ONE_TO_MANY,
                                targetModel: "iterableItem",
                                getter: "getBroken",
                            ),
                        ],
                    ),
                    new Model(
                        class: TestIterableRelItem::class,
                        type: "iterableItem",
                        attributes: [new Attribute(name: "id", type: "string")],
                    ),
                ],
                actions: [],
            ),
        );
    }

    public function testTraversableRelationIsMappedToArray(): void
    {
        $owner = new TestIterableRelOwner("o1", [
            new TestIterableRelItem("i1"),
            new TestIterableRelItem("i2"),
        ]);

        $result = $this->mapper->map(
            "iterableOwner",
            $owner,
            ["items"],
            ActionType::Read,
        );

        $this->assertIsArray($result->items);
        $this->assertCount(2, $result->items);
        $this->assertInstanceOf(stdClass::class, $result->items[0]);
        $this->assertSame("i1", $result->items[0]->id);
        $this->assertSame("i2", $result->items[1]->id);
    }

    public function testEmptyTraversableRelationIsMappedToEmptyArray(): void
    {
        $owner = new TestIterableRelOwner("o1");

        $result = $this->mapper->map(
            "iterableOwner",
            $owner,
            ["items"],
            ActionType::Read,
        );

        $this->assertSame([], $result->items);
    }

    public function testNonIterableRelationValueStillThrows(): void
    {
        // A scalar from a relation getter — a mismatch between the config and the entity
        //
        // Скаляр из геттера связи — рассинхрон конфига и сущности
        $owner = new TestIterableRelOwner("o1", [], "not-iterable");

        $this->expectException(ResponseTypeMismatchException::class);

        $this->mapper->map(
            "iterableOwner",
            $owner,
            ["broken"],
            ActionType::Read,
        );
    }
}
