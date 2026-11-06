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
use UniMethod\Frame\Mutator\{AttributeMutatorInterface, MutatorRegistry};

final class MutatorRegistrySlopCodeCoverTest extends TestCase
{
    private MutatorRegistry $registry;

    private AttributeAccessor $attributeAccessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->attributeAccessor = new AttributeAccessor();
        $this->registry = new MutatorRegistry([], $this->attributeAccessor);
    }

    public function testApplyPhaseCallsMutatorsAndUpdatesEntity(): void
    {
        // Arrange
        //
        // Подготовка
        $entity = new class () {
            private string $name = "John";

            public function getName(): string
            {
                return $this->name;
            }

            public function setName(string $value): void
            {
                $this->name = $value;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator
            ->expects($this->once())
            ->method("apply")
            ->with(
                $this->equalTo(PhaseType::AfterValidate),
                $this->identicalTo($entity),
                $this->isInstanceOf(Model::class),
                $this->equalTo("name"),
                $this->equalTo("John"),
                $this->equalTo(ActionType::Create),
            )
            ->willReturn("JOHN");

        $registry = new MutatorRegistry(
            ["trim" => $mutator],
            $this->attributeAccessor,
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: "getName",
                    setter: "setName",
                    mutators: new Mutators(afterValidate: ["trim"]),
                ),
            ],
        );

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::AfterValidate,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        // Assert
        //
        // Проверка
        $this->assertSame("JOHN", $entity->getName());
    }

    public function testApplyPhaseSkipsWhenNoMutators(): void
    {
        // Arrange
        //
        // Подготовка
        $entity = new class () {
            private string $title = "Initial";

            public function getTitle(): string
            {
                return $this->title;
            }

            public function setTitle(string $t): void
            {
                $this->title = $t;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["alias" => $mutator],
            $this->attributeAccessor,
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "title",
                    type: "string",
                    getter: "getTitle",
                    setter: "setTitle",
                ),
            ],
        );

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Update,
            [],
        );

        // Assert
        //
        // Проверка
        $this->assertSame("Initial", $entity->getTitle());
    }

    public function testApplyPhaseSkipsWhenGetterOrSetterMissing(): void
    {
        // Arrange
        //
        // Подготовка
        $entity = new class () {
            public string $name = "John";
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["m" => $mutator],
            $this->attributeAccessor,
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: false,
                    setter: "setName",
                    mutators: new Mutators(afterInput: ["m"]),
                ),
            ],
        );

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        // Assert
        //
        // Проверка
        $this->assertSame("John", $entity->name);
    }

    public function testApplyPhaseIgnoresUnknownMutatorAlias(): void
    {
        // Arrange
        //
        // Подготовка
        $entity = new class () {
            private string $name = "Bob";

            public function getName(): string
            {
                return $this->name;
            }

            public function setName(string $v): void
            {
                $this->name = $v;
            }
        };

        // 'unknown' is not defined in the map
        //
        // 'unknown' не определён в map
        $registry = new MutatorRegistry([], $this->attributeAccessor);

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: "getName",
                    setter: "setName",
                    mutators: new Mutators(afterInput: ["unknown"]),
                ),
            ],
        );

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Read,
            [],
        );

        // Assert
        //
        // Проверка
        $this->assertSame("Bob", $entity->getName());
    }

    public function testApplyPhaseSkipsWhenMutatorNotAllowedForPhase(): void
    {
        $entity = new class () {
            private string $name = "Alex";

            public function getName(): string
            {
                return $this->name;
            }

            public function setName(string $v): void
            {
                $this->name = $v;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["trim" => $mutator],
            $this->attributeAccessor,
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: "getName",
                    setter: "setName",
                    mutators: new Mutators(afterValidate: ["trim"]),
                ),
            ],
        );

        // The phase does not match the allowed one
        //
        // Фаза не совпадает с разрешённой
        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        $this->assertSame("Alex", $entity->getName());
    }

    public function testFindRelationReturnsNullWhenNotFound(): void
    {
        // Arrange
        //
        // Подготовка
        $model = new Model(
            class: "TestEntity",
            type: "test",
            attributes: [],
            relations: [
                new Relation(
                    name: "relation1",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "target1",
                ),
            ],
        );

        // Act & Assert
        //
        // Действие и проверка
        $reflectionMethod = new \ReflectionMethod(
            MutatorRegistry::class,
            "findRelation",
        );
        $this->assertNull(
            $reflectionMethod->invokeArgs($this->registry, [
                $model,
                "nonexistent",
            ]),
        );
    }

    public function testFindModelByTypeReturnsNullWhenNotFound(): void
    {
        // Arrange
        //
        // Подготовка
        $config = new Config(
            models: [new Model(class: "Class1", type: "type1")],
        );
        $this->registry->setConfig($config);

        // Act & Assert
        //
        // Действие и проверка
        $reflectionMethod = new \ReflectionMethod(
            MutatorRegistry::class,
            "findModelByType",
        );
        $this->assertNull(
            $reflectionMethod->invokeArgs($this->registry, ["nonexistent"]),
        );
    }
}
