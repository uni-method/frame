<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    Relation,
    RelationWriteMode,
    WritableConfig,
};
use UniMethod\Frame\Mapping\{AttributeAccessor, MappingToObject};
use UniMethod\Frame\Mapping\Exception\CollectionReplaceRequiresSetterException;
use UniMethod\Frame\Persistence\{EntityLocatorInterface, ListLocators};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\AddOnlyBox;
use UniMethod\Frame\Tests\Unit\Stub\{ChildNode, ParentNode, RecordingLocator, RecordingSaver};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * Collections on update — PATCH: the key is absent → do not touch; the key is present →
 * full replacement; null ≡ [] . Replacement requires a setter.
 *
 * Коллекции на update — PATCH: ключ отсутствует → не трогаем; ключ есть →
 * полная замена; null ≡ [] . Замена требует setter.
 */
final class MappingToObjectUpdateCollectionsTest extends TestCase
{
    public function testAbsentKeyLeavesCollectionUntouched(): void
    {
        $target = $this->parentWithTags(["a", "b", "c"]);

        $this->parentMapper()->map(
            "parent",
            (object) ["id" => "1"],
            ActionType::Update,
            "",
            "1",
            [],
            $target,
        );

        self::assertSame(["a", "b", "c"], $this->ids($target->tags));
    }

    public function testPresentKeyReplacesWholeCollection(): void
    {
        $target = $this->parentWithTags(["a", "b", "c"]);

        $this->parentMapper()->map(
            "parent",
            (object) ["tags" => [(object) ["id" => "a"]]],
            ActionType::Update,
            "tags",
            "1",
            [],
            $target,
        );

        self::assertSame(["a"], $this->ids($target->tags));
    }

    public function testNullEmptiesCollection(): void
    {
        $target = $this->parentWithTags(["a", "b"]);

        $this->parentMapper()->map(
            "parent",
            (object) ["tags" => null],
            ActionType::Update,
            "tags",
            "1",
            [],
            $target,
        );

        self::assertSame([], $target->tags);
    }

    public function testEmptyListEmptiesCollection(): void
    {
        $target = $this->parentWithTags(["a", "b"]);

        $this->parentMapper()->map(
            "parent",
            (object) ["tags" => []],
            ActionType::Update,
            "tags",
            "1",
            [],
            $target,
        );

        self::assertSame([], $target->tags);
    }

    public function testAddOnlyCollectionCannotBeUpdated(): void
    {
        $box = new AddOnlyBox(id: "1");
        $box->addItem(new ChildNode("a"));

        $mapper = $this->boxMapper(ActionType::Update);

        $this->expectException(CollectionReplaceRequiresSetterException::class);

        $mapper->map(
            "box",
            (object) ["items" => [(object) ["id" => "b"]]],
            ActionType::Update,
            "items",
            "1",
            [],
            $box,
        );
    }

    public function testAddOnlyCollectionIsFilledOnCreate(): void
    {
        $box = $this->boxMapper(ActionType::Create)->map(
            "box",
            (object) ["items" => [(object) ["id" => "a"], (object) ["id" => "b"]]],
            ActionType::Create,
            "items",
        );

        self::assertSame(["a", "b"], $this->ids($box->getItems()));
    }

    // ── helpers ─────────────────────────────────────────────────────────────
    //
    // ── хелперы ─────────────────────────────────────────────────────────────

    /**
     * @param list<string> $ids
     */
    private function parentWithTags(array $ids): ParentNode
    {
        return new ParentNode(
            id: "1",
            tags: array_map(static fn (string $id): ChildNode => new ChildNode($id), $ids),
        );
    }

    /**
     * @param list<ChildNode> $children
     * @return list<string|null>
     */
    private function ids(array $children): array
    {
        return array_map(static fn (ChildNode $child): ?string => $child->id, $children);
    }

    private function childModel(): Model
    {
        return new Model(
            class: ChildNode::class,
            type: "child",
            attributes: [
                new Attribute(name: "id", type: "string|null"),
                new Attribute(name: "name", type: "string|null"),
            ],
        );
    }

    private function parentMapper(): MappingToObject
    {
        $config = new Config(
            models: [
                new Model(
                    class: ParentNode::class,
                    type: "parent",
                    attributes: [new Attribute(name: "id", type: "string|null")],
                    relations: [
                        // setter null → auto-detection by the public property $tags
                        //
                        // setter null → автоопределение по публичному свойству $tags
                        new Relation(name: "tags", type: Relation::MANY_TO_MANY, targetModel: "child"),
                    ],
                ),
                $this->childModel(),
            ],
            actions: [
                new Action(
                    type: ActionType::Update,
                    model: "parent",
                    writable: ["tags" => new WritableConfig(RelationWriteMode::Reference)],
                    entityLocator: "loc",
                    entitySaver: "saver",
                ),
                new Action(type: ActionType::Read, model: "child", entityLocator: "loc"),
            ],
            locators: ["loc" => RecordingLocator::class],
            savers: ["saver" => RecordingSaver::class],
        );

        return $this->mapper($config);
    }

    private function boxMapper(ActionType $actionType): MappingToObject
    {
        $config = new Config(
            models: [
                new Model(
                    class: AddOnlyBox::class,
                    type: "box",
                    attributes: [new Attribute(name: "id", type: "string|null")],
                    relations: [
                        new Relation(
                            name: "items",
                            type: Relation::ONE_TO_MANY,
                            targetModel: "child",
                            getter: "getItems",
                            setter: false,
                            add: "addItem",
                        ),
                    ],
                ),
                $this->childModel(),
            ],
            actions: [
                new Action(
                    type: $actionType,
                    model: "box",
                    writable: ["items" => new WritableConfig(RelationWriteMode::Reference)],
                    entityLocator: "loc",
                    entitySaver: "saver",
                ),
                new Action(type: ActionType::Read, model: "child", entityLocator: "loc"),
            ],
            locators: ["loc" => RecordingLocator::class],
            savers: ["saver" => RecordingSaver::class],
        );

        return $this->mapper($config);
    }

    private function mapper(Config $config): MappingToObject
    {
        // Reference locator: returns a ChildNode with the requested id
        //
        // Локатор ссылок: отдаёт ChildNode с запрошенным id
        $locator = new class () implements EntityLocatorInterface {
            public function find(
                string $className,
                int|string $id,
                array $include = [],
                ?object $object = null,
            ): ChildNode {
                return new ChildNode((string) $id);
            }
        };

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            new ListLocators(["loc" => $locator]),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        return $mapper;
    }
}
