<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\{MockObject, Stub};
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
use UniMethod\Frame\Persistence\{
    EntityLocatorContainerInterface,
    EntityLocatorInterface,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{TestRule, TestTaskType};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * ReferenceOrCreate mode: the branch is chosen by the data — an object with exactly one
 * id field is resolved by the locator, everything else is created.
 *
 * Режим ReferenceOrCreate: ветка выбирается по данным — объект ровно с одним
 * полем id резолвится локатором, всё остальное создаётся.
 */
final class MappingToObjectReferenceOrCreateTest extends TestCase
{
    private EntityLocatorContainerInterface&Stub $locatorContainer;

    private EntityLocatorInterface&MockObject $entityLocator;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestRule")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestRule {
                    private ?string $title = null;
                    private ?TestTaskType $taskType = null;
                    private array $taskTypes = [];
                    public function setTitle(?string $title): void { $this->title = $title; }
                    public function setTaskType(?TestTaskType $taskType): void { $this->taskType = $taskType; }
                    public function setTaskTypes(array $taskTypes): void { $this->taskTypes = $taskTypes; }
                    public function getTitle(): ?string { return $this->title; }
                    public function getTaskType(): ?TestTaskType { return $this->taskType; }
                    public function getTaskTypes(): array { return $this->taskTypes; }
                }

                class TestTaskType {
                    private ?string $id = null;
                    private ?string $code = null;
                    private ?string $name = null;
                    public function setId(?string $id): void { $this->id = $id; }
                    public function setCode(?string $code): void { $this->code = $code; }
                    public function setName(?string $name): void { $this->name = $name; }
                    public function getId(): ?string { return $this->id; }
                    public function getCode(): ?string { return $this->code; }
                    public function getName(): ?string { return $this->name; }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->entityLocator = $this->createMock(EntityLocatorInterface::class);
        $this->locatorContainer = $this->createStub(
            EntityLocatorContainerInterface::class,
        );
        $this->locatorContainer
            ->method("getEntityLocatorByName")
            ->willReturn($this->entityLocator);
    }

    public function testReferencePayloadFetchesExistingEntity(): void
    {
        $existing = new TestTaskType();
        $existing->setId("tt-1");
        $existing->setCode("watering");
        $existing->setName("Полив");

        $this->entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestTaskType::class, "tt-1", [])
            ->willReturn($existing);

        $source = (object) [
            "title" => "Полив",
            "taskType" => (object) ["id" => "tt-1"],
        ];

        /** @var TestRule $result */
        $result = $this->mapper()->map(
            "rule",
            $source,
            ActionType::Create,
            "taskType",
        );

        $this->assertSame($existing, $result->getTaskType());
    }

    public function testCreatePayloadBuildsNewEntity(): void
    {
        $this->entityLocator->expects($this->never())->method("find");

        $source = (object) [
            "title" => "Досветка",
            "taskType" => (object) [
                "code" => "lighting",
                "name" => "Освещение",
            ],
        ];

        /** @var TestRule $result */
        $result = $this->mapper()->map(
            "rule",
            $source,
            ActionType::Create,
            "taskType",
        );

        $this->assertInstanceOf(TestTaskType::class, $result->getTaskType());
        $this->assertNull($result->getTaskType()->getId());
        $this->assertSame("lighting", $result->getTaskType()->getCode());
        $this->assertSame("Освещение", $result->getTaskType()->getName());
    }

    public function testIdWithExtraFieldsBuildsNewEntity(): void
    {
        $this->entityLocator->expects($this->never())->method("find");

        $source = (object) [
            "title" => "Досветка",
            "taskType" => (object) [
                "id" => "tt-1",
                "code" => "lighting",
                "name" => "Освещение",
            ],
        ];

        /** @var TestRule $result */
        $result = $this->mapper()->map(
            "rule",
            $source,
            ActionType::Create,
            "taskType",
        );

        $this->assertInstanceOf(TestTaskType::class, $result->getTaskType());
        $this->assertSame("tt-1", $result->getTaskType()->getId());
        $this->assertSame("lighting", $result->getTaskType()->getCode());
    }

    public function testCollectionMixesReferenceAndCreate(): void
    {
        $existing = new TestTaskType();
        $existing->setId("tt-1");
        $existing->setCode("watering");

        $this->entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestTaskType::class, "tt-1", [])
            ->willReturn($existing);

        $source = (object) [
            "title" => "Смешанная",
            "taskTypes" => [
                (object) ["id" => "tt-1"],
                (object) ["code" => "lighting", "name" => "Освещение"],
            ],
        ];

        /** @var TestRule $result */
        $result = $this->mapper()->map(
            "rule",
            $source,
            ActionType::Create,
            "taskTypes",
        );

        $taskTypes = $result->getTaskTypes();
        $this->assertCount(2, $taskTypes);
        $this->assertSame($existing, $taskTypes[0]);
        $this->assertNotSame($existing, $taskTypes[1]);
        $this->assertNull($taskTypes[1]->getId());
        $this->assertSame("lighting", $taskTypes[1]->getCode());
    }

    private function mapper(): MappingToObject
    {
        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($this->config());

        return $mapper;
    }

    private function config(): Config
    {
        return new Config(
            models: [
                new Model(
                    class: TestRule::class,
                    type: "rule",
                    attributes: [
                        new Attribute("title", "string|null", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "taskType",
                            "manyToOne",
                            "taskType",
                            setter: "setTaskType",
                        ),
                        new Relation(
                            "taskTypes",
                            "oneToMany",
                            "taskType",
                            setter: "setTaskTypes",
                        ),
                    ],
                ),
                new Model(
                    class: TestTaskType::class,
                    type: "taskType",
                    attributes: [
                        new Attribute("id", "string|null", setter: "setId"),
                        new Attribute("code", "string|null", setter: "setCode"),
                        new Attribute("name", "string|null", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "taskType", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "rule",
                    writable: [
                        "taskType" => new WritableConfig(
                            mode: RelationWriteMode::ReferenceOrCreate,
                        ),
                        "taskTypes" => new WritableConfig(
                            mode: RelationWriteMode::ReferenceOrCreate,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );
    }
}
