<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mutator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{ActionType, Attribute, Model, Mutators, PhaseType};
use UniMethod\Frame\Mapping\AttributeAccessor;
use UniMethod\Frame\Mutator\{AttributeMutatorInterface, MutatorRegistry};

/**
 * Tests for checking fixes in MutatorRegistry
 * They check working with public properties and auto-detection
 *
 * Тесты для проверки исправлений в MutatorRegistry
 * Проверяют работу с публичными свойствами и автоопределение
 */
final class MutatorRegistryFixesTest extends TestCase
{
    public function testApplyPhaseWorksWithPublicProperties(): void
    {
        $entity = new class () {
            public string $title = "original";

            public int $views = 100;
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator
            ->expects($this->once())
            ->method("apply")
            ->with(
                $this->equalTo(PhaseType::AfterValidate),
                $this->identicalTo($entity),
                $this->isInstanceOf(Model::class),
                $this->equalTo("title"),
                $this->equalTo("original"),
                $this->equalTo(ActionType::Create),
            )
            ->willReturn("MODIFIED");

        $registry = new MutatorRegistry(
            ["uppercase" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "title",
                    type: "string",
                    getter: "title",
                    setter: "title",
                    mutators: new Mutators(afterValidate: ["uppercase"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterValidate,
            $entity,
            $model,
            ActionType::Create,
            [],
        );

        $this->assertSame("MODIFIED", $entity->title);
    }

    public function testApplyPhaseWorksWithAutodetection(): void
    {
        $entity = new class () {
            public string $email = "test@example.com";
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator
            ->expects($this->once())
            ->method("apply")
            ->willReturnCallback(
                fn (
                    $phase,
                    $entity,
                    $model,
                    $name,
                    $value,
                    $action,
                ): string => strtoupper($value),
            );

        $registry = new MutatorRegistry(
            ["upper" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "email",
                    type: "string",
                    mutators: new Mutators(afterInput: ["upper"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Update,
            [],
        );

        $this->assertSame("TEST@EXAMPLE.COM", $entity->email);
    }

    public function testApplyPhaseWorksWithMixedAccessors(): void
    {
        $entity = new class () {
            public string $name = "john";

            public function getName(): string
            {
                return $this->name;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn("JOHN DOE");

        $registry = new MutatorRegistry(
            ["format" => $mutator],
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
                    setter: "name",
                    mutators: new Mutators(afterInput: ["format"]),
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

        $this->assertSame("JOHN DOE", $entity->name);
    }

    public function testApplyPhaseAppliesMultipleMutatorsInSequence(): void
    {
        $entity = new class () {
            public string $text = "  hello world  ";
        };

        $trimMutator = $this->createMock(AttributeMutatorInterface::class);
        $trimMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn("hello world");

        $upperMutator = $this->createMock(AttributeMutatorInterface::class);
        $upperMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn("HELLO WORLD");

        $registry = new MutatorRegistry(
            [
                "trim" => $trimMutator,
                "upper" => $upperMutator,
            ],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "text",
                    type: "string",
                    getter: "text",
                    setter: "text",
                    mutators: new Mutators(afterInput: ["trim", "upper"]),
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

        $this->assertSame("HELLO WORLD", $entity->text);
    }

    public function testApplyPhaseHandlesMultipleAttributes(): void
    {
        $entity = new class () {
            public string $firstName = "john";

            public string $lastName = "doe";
        };

        $upperMutator = $this->createMock(AttributeMutatorInterface::class);
        $upperMutator
            ->expects($this->exactly(2))
            ->method("apply")
            ->willReturnCallback(
                fn ($phase, $entity, $model, $name, $value): string => strtoupper(
                    $value,
                ),
            );

        $registry = new MutatorRegistry(
            ["upper" => $upperMutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "firstName",
                    type: "string",
                    mutators: new Mutators(afterInput: ["upper"]),
                ),
                new Attribute(
                    name: "lastName",
                    type: "string",
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

        $this->assertSame("JOHN", $entity->firstName);
        $this->assertSame("DOE", $entity->lastName);
    }

    public function testApplyPhaseHandlesDifferentDataTypes(): void
    {
        $entity = new class () {
            public int $age = 25;
        };

        $incrementMutator = $this->createStub(AttributeMutatorInterface::class);
        $incrementMutator
            ->method("apply")
            ->willReturnCallback(
                fn ($phase, $entity, $model, $name, $value): int => $value + 1,
            );

        $registry = new MutatorRegistry(
            ["increment" => $incrementMutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "age",
                    type: "int",
                    getter: "age",
                    setter: "age",
                    mutators: new Mutators(afterInput: ["increment"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Update,
            [],
        );

        $this->assertSame(26, $entity->age);
    }

    public function testApplyPhaseSkipsWhenAccessorsExplicitlyDisabled(): void
    {
        $entity = new class () {
            public string $internal = "secret";
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["leak" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "internal",
                    type: "string",
                    getter: false,
                    setter: false,
                    mutators: new Mutators(afterInput: ["leak"]),
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

        $this->assertSame("secret", $entity->internal);
    }

    public function testApplyPhaseSkipsPrivatePropertiesInAutodetection(): void
    {
        $entity = new class () {
            private string $private = "hidden";

            public function getPrivateValue(): string
            {
                return $this->private;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["test" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "private",
                    type: "string",
                    mutators: new Mutators(afterInput: ["test"]),
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

        $this->assertSame("hidden", $entity->getPrivateValue());
    }

    public function testApplyPhaseSkipsReadonlyPropertiesWithoutSetter(): void
    {
        $entity = new class () {
            public readonly string $id;

            public function __construct()
            {
                $this->id = "abc-123";
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["modify" => $mutator],
            new AttributeAccessor(),
        );

        $model = new Model(
            class: get_class($entity),
            type: "test",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "id",
                    setter: false,
                    mutators: new Mutators(afterInput: ["modify"]),
                ),
            ],
        );

        $registry->applyPhase(
            PhaseType::AfterInput,
            $entity,
            $model,
            ActionType::Update,
            [],
        );

        $this->assertSame("abc-123", $entity->id);
    }
}
