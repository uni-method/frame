<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mutator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    ActionType,
    Attribute,
    Config,
    Model,
    Mutators,
    PhaseType,
    Relation,
};
use UniMethod\Frame\Mapping\AttributeAccessor;
use UniMethod\Frame\Mutator\{
    AttributeMutatorInterface,
    MutatorRegistry,
    RelationMutatorInterface,
};
use UniMethod\Frame\Tests\Unit\Mutator\Stub\{
    RelationTestComment,
    RelationTestPost,
};
use UniMethod\Frame\Tests\Unit\Stub\{ChildNode, ParentNode, UpperMutator};

/**
 * Tests for checking how mutators work with relations
 *
 * Тесты для проверки работы мутаторов со связями (relations)
 */
final class MutatorRegistryRelationsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mutator\Stub\RelationTestComment",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mutator\Stub;

                class RelationTestComment {
                    public function __construct(
                        public string $text = "",
                    ) {}
                }

                class RelationTestPost {
                    public function __construct(
                        public string $title = "",
                        public ?RelationTestComment $comment = null,
                        public array $tags = [],
                    ) {}
                }
            ');
        }
    }

    public function testApplyPhaseWorksWithRelationMutators(): void
    {
        $relatedEntity = new RelationTestComment(text: "hello");
        $entity = new RelationTestPost(title: "Post", comment: $relatedEntity);

        $mutator = $this->createMock(RelationMutatorInterface::class);
        $mutator
            ->expects($this->once())
            ->method("apply")
            ->with(
                $this->equalTo(PhaseType::BeforeOutput),
                $this->identicalTo($entity),
                $this->isInstanceOf(Model::class),
                $this->equalTo("comment"),
                $this->identicalTo($relatedEntity),
                $this->equalTo(ActionType::Read),
            )
            ->willReturn(null);

        $registry = new MutatorRegistry(
            map: [],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["nullify" => $mutator],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [new Attribute(name: "title", type: "string")],
            relations: [
                new Relation(
                    name: "comment",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "comment",
                    mutators: new Mutators(beforeOutput: ["nullify"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $entity,
            $model,
            ActionType::Read,
            [],
        );

        $this->assertNull($entity->comment);
    }

    public function testApplyPhaseAppliesMultipleRelationMutatorsInSequence(): void
    {
        $entity = new RelationTestPost(title: "Post", tags: ["php", "symfony"]);

        $filterMutator = $this->createMock(RelationMutatorInterface::class);
        $filterMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn(["php"]);

        $upperMutator = $this->createMock(RelationMutatorInterface::class);
        $upperMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn(["PHP"]);

        $registry = new MutatorRegistry(
            map: [],
            attributeAccessor: new AttributeAccessor(),
            relationMap: [
                "filter" => $filterMutator,
                "upper" => $upperMutator,
            ],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [new Attribute(name: "title", type: "string")],
            relations: [
                new Relation(
                    name: "tags",
                    type: Relation::ONE_TO_MANY,
                    targetModel: "tag",
                    mutators: new Mutators(afterInput: ["filter", "upper"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        $this->assertSame(["PHP"], $entity->tags);
    }

    public function testApplyPhaseSkipsRelationWhenAccessorsDisabled(): void
    {
        $relatedEntity = new RelationTestComment(text: "secret");
        $entity = new RelationTestPost(title: "Post", comment: $relatedEntity);

        $mutator = $this->createMock(RelationMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            map: [],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["test" => $mutator],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [new Attribute(name: "title", type: "string")],
            relations: [
                new Relation(
                    name: "comment",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "comment",
                    getter: false,
                    setter: false,
                    mutators: new Mutators(beforeOutput: ["test"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $entity,
            $model,
            ActionType::Read,
            [],
        );

        $this->assertSame($relatedEntity, $entity->comment);
    }

    public function testApplyPhaseHandlesBothAttributeAndRelationMutators(): void
    {
        $relatedEntity = new RelationTestComment(text: "comment");
        $entity = new RelationTestPost(title: "hello", comment: $relatedEntity);

        $upperMutator = $this->createMock(AttributeMutatorInterface::class);
        $upperMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturnCallback(
                fn ($phase, $entity, $model, $name, $value): string => strtoupper(
                    $value,
                ),
            );

        $nullifyMutator = $this->createMock(RelationMutatorInterface::class);
        $nullifyMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn(null);

        $registry = new MutatorRegistry(
            map: ["upper" => $upperMutator],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["nullify" => $nullifyMutator],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [
                new Attribute(
                    name: "title",
                    type: "string",
                    mutators: new Mutators(afterInput: ["upper"]),
                ),
            ],
            relations: [
                new Relation(
                    name: "comment",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "comment",
                    mutators: new Mutators(afterInput: ["nullify"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        $this->assertSame("HELLO", $entity->title);
        $this->assertNull($entity->comment);
    }

    public function testApplyPhaseRelationMutatorsApplyBeforeRecursion(): void
    {
        $comment = new RelationTestComment(text: "original");
        $entity = new RelationTestPost(title: "Post", comment: $comment);

        $config = new Config(
            models: [
                new Model(
                    class: RelationTestPost::class,
                    type: "post",
                    attributes: [new Attribute(name: "title", type: "string")],
                    relations: [
                        new Relation(
                            name: "comment",
                            type: Relation::ONE_TO_ONE,
                            targetModel: "comment",
                            mutators: new Mutators(beforeOutput: ["replace"]),
                        ),
                    ],
                ),
                new Model(
                    class: RelationTestComment::class,
                    type: "comment",
                    attributes: [
                        new Attribute(
                            name: "text",
                            type: "string",
                            mutators: new Mutators(beforeOutput: ["upper"]),
                        ),
                    ],
                ),
            ],
            actions: [],
        );

        $newComment = new RelationTestComment(text: "replaced");

        $replaceMutator = $this->createStub(RelationMutatorInterface::class);
        $replaceMutator->method("apply")->willReturn($newComment);

        $upperMutator = $this->createStub(AttributeMutatorInterface::class);
        $upperMutator
            ->method("apply")
            ->willReturnCallback(fn ($p, $e, $m, $n, $v): string => strtoupper($v));

        $registry = new MutatorRegistry(
            map: ["upper" => $upperMutator],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["replace" => $replaceMutator],
        );
        $registry->setConfig($config);

        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $entity,
            $config->models[0],
            ActionType::Read,
            ["comment" => []],
        );

        // The relation is replaced with a new object
        //
        // Связь заменена на новый объект
        $this->assertSame($newComment, $entity->comment);
        // The recursion was applied to the new object
        //
        // Рекурсия применилась к новому объекту
        $this->assertSame("REPLACED", $entity->comment->text);
    }

    public function testApplyPhaseRelationMutatorsWorkWithDifferentPhases(): void
    {
        $entity = new RelationTestPost(title: "Post", tags: ["a", "b"]);

        $afterInputMutator = $this->createMock(RelationMutatorInterface::class);
        $afterInputMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn(["a", "b", "c"]);

        $registry = new MutatorRegistry(
            map: [],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["add" => $afterInputMutator],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [],
            relations: [
                new Relation(
                    name: "tags",
                    type: Relation::ONE_TO_MANY,
                    targetModel: "tag",
                    mutators: new Mutators(
                        afterInput: ["add"],
                        afterValidate: [],
                        beforeOutput: [],
                    ),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        $this->assertSame(["a", "b", "c"], $entity->tags);
    }

    public function testApplyPhaseUsesRelationMutatorSetterWhenSetterIsFalse(): void
    {
        $relatedEntity = new RelationTestComment(text: "original");
        $entity = new RelationTestPost(title: "Post", comment: $relatedEntity);

        $newComment = new RelationTestComment(text: "replaced");

        $mutator = $this->createMock(RelationMutatorInterface::class);
        $mutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn($newComment);

        // Add entities with mutatorSetter via an anonymous wrapper class
        //
        // Добавляем сущности с mutatorSetter через анонимный класс-обёртку
        $wrappedEntity = new class ($entity) {
            private object $commentForMutator;

            public function __construct(private object $inner)
            {
                $this->commentForMutator = $this->inner->comment;
            }

            public function getComment(): mixed
            {
                return $this->inner->comment;
            }

            public function setCommentForMutator(mixed $value): void
            {
                $this->commentForMutator = $value;
            }

            public function getCommentForMutatorValue(): mixed
            {
                return $this->commentForMutator;
            }
        };

        $registry = new MutatorRegistry(
            map: [],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["replace" => $mutator],
        );

        $model = new Model(
            class: get_class($wrappedEntity),
            type: "post",
            attributes: [],
            relations: [
                new Relation(
                    name: "comment",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "comment",
                    getter: "getComment",
                    setter: false,
                    mutatorSetter: "setCommentForMutator",
                    mutators: new Mutators(beforeOutput: ["replace"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $wrappedEntity,
            $model,
            ActionType::Read,
            [],
        );

        $this->assertSame(
            $newComment,
            $wrappedEntity->getCommentForMutatorValue(),
        );
        $this->assertSame($relatedEntity, $wrappedEntity->getComment());
    }

    public function testApplyPhaseSkipsRelationWhenBothSetterAndMutatorSetterAreFalse(): void
    {
        $relatedEntity = new RelationTestComment(text: "secret");
        $entity = new RelationTestPost(title: "Post", comment: $relatedEntity);

        $mutator = $this->createMock(RelationMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            map: [],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["test" => $mutator],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [],
            relations: [
                new Relation(
                    name: "comment",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "comment",
                    getter: "getComment",
                    setter: false,
                    mutatorSetter: false,
                    mutators: new Mutators(beforeOutput: ["test"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $entity,
            $model,
            ActionType::Read,
            [],
        );

        $this->assertSame($relatedEntity, $entity->comment);
    }

    public function testRelationMutatorDoesNotInterfereWithAttributeMutator(): void
    {
        $entity = new RelationTestPost(title: "test", tags: ["a"]);

        // Register a mutator only for attributes
        //
        // Регистрируем мутатор только для атрибутов
        $attrMutator = $this->createMock(AttributeMutatorInterface::class);
        $attrMutator->expects($this->never())->method("apply");

        // Register a mutator only for relations
        //
        // Регистрируем мутатор только для связей
        $relMutator = $this->createMock(RelationMutatorInterface::class);
        $relMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn(["a", "b"]);

        $registry = new MutatorRegistry(
            map: ["shared" => $attrMutator],
            attributeAccessor: new AttributeAccessor(),
            relationMap: ["shared" => $relMutator],
        );

        $model = new Model(
            class: RelationTestPost::class,
            type: "post",
            attributes: [new Attribute(name: "title", type: "string")],
            relations: [
                new Relation(
                    name: "tags",
                    type: Relation::ONE_TO_MANY,
                    targetModel: "tag",
                    mutators: new Mutators(afterInput: ["shared"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        $this->assertSame(["a", "b"], $entity->tags);
        $this->assertSame("test", $entity->title);
    }

    /**
     * Mutators of the target model are applied through relations of all four types:
     * oneToOne, oneToMany, manyToOne, manyToMany.
     *
     * Мутаторы целевой модели применяются через связи всех четырёх типов:
     * oneToOne, oneToMany, manyToOne, manyToMany.
     */
    public function testApplyPhaseRecursesIntoAllRelationTypes(): void
    {
        $childModel = new Model(
            class: ChildNode::class,
            type: "child",
            attributes: [
                new Attribute(name: "id", type: "string"),
                new Attribute(
                    name: "name",
                    type: "string",
                    mutators: new Mutators(beforeOutput: ["upper"]),
                ),
            ],
        );

        $parentModel = new Model(
            class: ParentNode::class,
            type: "parent",
            attributes: [new Attribute(name: "id", type: "string")],
            relations: [
                new Relation(name: "single", type: Relation::ONE_TO_ONE, targetModel: "child"),
                new Relation(name: "many", type: Relation::ONE_TO_MANY, targetModel: "child"),
                new Relation(name: "owner", type: Relation::MANY_TO_ONE, targetModel: "child"),
                new Relation(name: "tags", type: Relation::MANY_TO_MANY, targetModel: "child"),
            ],
        );

        $registry = new MutatorRegistry(
            ["upper" => new UpperMutator()],
            new AttributeAccessor(),
        );
        $registry->setConfig(new Config(models: [$parentModel, $childModel]));

        $parent = new ParentNode(
            id: "1",
            single: new ChildNode("s", "a"),
            many: [new ChildNode("m", "b")],
            owner: new ChildNode("o", "c"),
            tags: [new ChildNode("t", "d")],
        );

        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $parent,
            $parentModel,
            ActionType::Read,
            ["single" => [], "many" => [], "owner" => [], "tags" => []],
        );

        $this->assertSame("A", $parent->single->name, "oneToOne");
        $this->assertSame("B", $parent->many[0]->name, "oneToMany");
        $this->assertSame("C", $parent->owner->name, "manyToOne");
        $this->assertSame("D", $parent->tags[0]->name, "manyToMany");
    }
}
