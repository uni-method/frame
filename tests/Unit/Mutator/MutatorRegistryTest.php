<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mutator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{ActionType, Attribute, Model, Mutators, PhaseType};
use UniMethod\Frame\Mapping\AttributeAccessor;
use UniMethod\Frame\Mutator\{AttributeMutatorInterface, MutatorRegistry};

final class MutatorRegistryTest extends TestCase
{
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

            public function getNameValue(): string
            {
                return $this->name;
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
            new AttributeAccessor(),
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
        $this->assertSame(
            "JOHN",
            $entity->getNameValue(),
            "Mutator should update entity property",
        );
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
            new AttributeAccessor(),
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
            new AttributeAccessor(),
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

            public function getNameValue(): string
            {
                return $this->name;
            }
        };

        // 'unknown' is not defined in the map
        //
        // 'unknown' не определён в map
        $registry = new MutatorRegistry([], new AttributeAccessor());

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
        $this->assertSame("Bob", $entity->getNameValue());
    }

    public function testApplyPhaseUsesMutatorSetterWhenSetterIsFalse(): void
    {
        $entity = new class () {
            private int $balance = 100;

            private int $balanceForMutator = 100;

            public function getBalance(): int
            {
                return $this->balance;
            }

            public function setBalanceMutator(int $value): void
            {
                $this->balanceForMutator = $value;
            }

            public function getBalanceMutatorValue(): int
            {
                return $this->balanceForMutator;
            }

            public function getBalanceValue(): int
            {
                return $this->balance;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->once())->method("apply")->willReturn(0);

        $registry = new MutatorRegistry(
            ["hide" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "balance",
                    type: "int",
                    getter: "getBalance",
                    setter: false,
                    mutatorSetter: "setBalanceMutator",
                    mutators: new Mutators(beforeOutput: ["hide"]),
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

        $this->assertSame(0, $entity->getBalanceMutatorValue());
        $this->assertSame(100, $entity->getBalanceValue());
    }

    public function testApplyPhasePrefersRegularSetterWhenMutatorSetterAbsent(): void
    {
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
        $mutator->expects($this->once())->method("apply")->willReturn("JOHN");

        $registry = new MutatorRegistry(
            ["upper" => $mutator],
            new AttributeAccessor(),
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
                    mutators: new Mutators(afterInput: ["upper"]),
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

        $this->assertSame("JOHN", $entity->getName());
    }

    public function testApplyPhaseSkipsWhenBothSetterAndMutatorSetterAreFalse(): void
    {
        $entity = new class () {
            public string $name = "John";
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["upper" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: "getName",
                    setter: false,
                    mutatorSetter: false,
                    mutators: new Mutators(afterInput: ["upper"]),
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

        $this->assertSame("John", $entity->name);
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
            new AttributeAccessor(),
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
}
