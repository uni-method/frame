<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    AccessConfig,
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
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{TestNullCompany, TestNullUser};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * Tests for checking correct behavior when unlinking relations via null
 *
 * Тесты для проверки корректного поведения при отвязывании связей через null
 */
final class MappingToObjectNullRelationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestNullUser")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestNullUser {
                    private string $id;
                    private string $name;
                    private ?TestNullCompany $company = null;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function setCompany(?TestNullCompany $company): void { $this->company = $company; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getCompany(): ?TestNullCompany { return $this->company; }
                }

                class TestNullCompany {
                    private string $id;
                    private string $name;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                }
            ');
        }
    }

    /**
     * Checks that an explicit null unlinks an existing relation on Update
     *
     * Проверяет, что явный null отвязывает существующую связь при Update
     */
    public function testUpdateWithNullRelationUnlinksExistingRelation(): void
    {
        // Create an existing entity with the relation set
        //
        // Создаем существующую сущность с установленной связью
        $existingUser = new TestNullUser();
        $existingUser->setId("user-123");
        $existingUser->setName("John Doe");

        $existingCompany = new TestNullCompany();
        $existingCompany->setId("company-456");
        $existingCompany->setName("Acme Corp");

        $existingUser->setCompany($existingCompany);

        // Mock the locator so that it returns the existing entity
        //
        // Мокаем locator, чтобы он возвращал существующую сущность
        $locatorContainer = $this->createMock(
            EntityLocatorContainerInterface::class,
        );
        $entityLocator = $this->createMock(EntityLocatorInterface::class);

        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestNullUser::class, "user-123", [])
            ->willReturn($existingUser);

        $locatorContainer
            ->expects($this->once())
            ->method("getEntityLocatorByName")
            ->with("default")
            ->willReturn($entityLocator);

        $config = new Config(
            models: [
                new Model(
                    class: TestNullUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            getter: "getCompany",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestNullCompany::class,
                    type: "company",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Update,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        // Update with an explicit null for unlinking
        //
        // Update с явным null для отвязывания
        $source = (object) [
            // Explicitly unlink the company
            //
            // Явно отвязываем компанию
            "company" => null,
        ];

        /** @var TestNullUser $result */
        $result = $mapper->map(
            "user",
            $source,
            ActionType::Update,
            "company",
            "user-123",
        );

        $this->assertInstanceOf(TestNullUser::class, $result);
        $this->assertSame("user-123", $result->getId());
        // Check that the relation was unlinked
        //
        // Проверяем, что связь была отвязана
        $this->assertNull(
            $result->getCompany(),
            "Company relation should be unlinked when null is explicitly passed",
        );
    }

    /**
     * Checks that the absence of the relation key does not change the existing relation on Update
     *
     * Проверяет, что отсутствие ключа связи не изменяет существующую связь при Update
     */
    public function testUpdateWithoutRelationKeyDoesNotChangeExistingRelation(): void
    {
        // Create an existing entity with the relation set
        //
        // Создаем существующую сущность с установленной связью
        $existingUser = new TestNullUser();
        $existingUser->setId("user-123");
        $existingUser->setName("John Doe");

        $existingCompany = new TestNullCompany();
        $existingCompany->setId("company-456");
        $existingCompany->setName("Acme Corp");

        $existingUser->setCompany($existingCompany);

        // Mock the locator
        //
        // Мокаем locator
        $locatorContainer = $this->createMock(
            EntityLocatorContainerInterface::class,
        );
        $entityLocator = $this->createMock(EntityLocatorInterface::class);

        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestNullUser::class, "user-123", [])
            ->willReturn($existingUser);

        $locatorContainer
            ->expects($this->once())
            ->method("getEntityLocatorByName")
            ->with("default")
            ->willReturn($entityLocator);

        $config = new Config(
            models: [
                new Model(
                    class: TestNullUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            getter: "getCompany",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestNullCompany::class,
                    type: "company",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Update,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        // Update WITHOUT the company key - must not touch the relation
        //
        // Update БЕЗ ключа company - не должен трогать связь
        $source = (object) [
            // Update only the name
            //
            // Обновляем только имя
            "name" => "Jane Doe",
        ];

        /** @var TestNullUser $result */
        $result = $mapper->map(
            "user",
            $source,
            ActionType::Update,
            "",
            "user-123",
        );

        $this->assertInstanceOf(TestNullUser::class, $result);
        $this->assertSame("user-123", $result->getId());
        $this->assertSame("Jane Doe", $result->getName());
        // Check that the relation was NOT touched
        //
        // Проверяем, что связь НЕ была затронута
        $this->assertNotNull(
            $result->getCompany(),
            "Company relation should remain untouched when key is not present",
        );
        $this->assertSame("company-456", $result->getCompany()->getId());
        $this->assertSame("Acme Corp", $result->getCompany()->getName());
    }

    /**
     * Checks that null in a Create action also works correctly
     *
     * Проверяет, что null в Create действии также работает корректно
     */
    public function testCreateWithNullRelationCreatesEntityWithoutRelation(): void
    {
        $locatorContainer = $this->createStub(
            EntityLocatorContainerInterface::class,
        );

        $config = new Config(
            models: [
                new Model(
                    class: TestNullUser::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestNullCompany::class,
                    type: "company",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        // Create with an explicit null for the relation
        //
        // Create с явным null для связи
        $source = (object) [
            "id" => "user-789",
            "name" => "Alice",
            // Explicitly specify that there is no company
            //
            // Явно указываем, что компании нет
            "company" => null,
        ];

        /** @var TestNullUser $result */
        $result = $mapper->map("user", $source, ActionType::Create, "company");

        $this->assertInstanceOf(TestNullUser::class, $result);
        $this->assertSame("user-789", $result->getId());
        $this->assertSame("Alice", $result->getName());
        $this->assertNull(
            $result->getCompany(),
            "Company should be null when explicitly set to null in Create",
        );
    }

    /**
     * Checks that WritableConfig with AccessConfig does not change behavior with null (Update)
     *
     * Проверяет, что WritableConfig с AccessConfig не меняет поведение при null (Update)
     */
    public function testUpdateWithNullRelationAndWritableConfigWithAccess(): void
    {
        $existingUser = new TestNullUser();
        $existingUser->setId("user-123");
        $existingUser->setName("John Doe");

        $existingCompany = new TestNullCompany();
        $existingCompany->setId("company-456");
        $existingCompany->setName("Acme Corp");

        $existingUser->setCompany($existingCompany);

        $locatorContainer = $this->createMock(
            EntityLocatorContainerInterface::class,
        );
        $entityLocator = $this->createMock(EntityLocatorInterface::class);

        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestNullUser::class, "user-123", [])
            ->willReturn($existingUser);

        $locatorContainer
            ->expects($this->once())
            ->method("getEntityLocatorByName")
            ->with("default")
            ->willReturn($entityLocator);

        $config = new Config(
            models: [
                new Model(
                    class: TestNullUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            getter: "getCompany",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestNullCompany::class,
                    type: "company",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Update,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: new AccessConfig(
                                authRequired: true,
                                roles: ["admin"],
                            ),
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "company" => null,
        ];

        /** @var TestNullUser $result */
        $result = $mapper->map(
            "user",
            $source,
            ActionType::Update,
            "company",
            "user-123",
        );

        $this->assertNull(
            $result->getCompany(),
            "Company should be unlinked even when WritableConfig has AccessConfig",
        );
    }

    /**
     * Checks that WritableConfig with AccessConfig does not change behavior
     * when the relation key is absent (Update)
     *
     * Проверяет, что WritableConfig с AccessConfig не меняет поведение
     * при отсутствии ключа связи (Update)
     */
    public function testUpdateWithoutRelationKeyAndWritableConfigWithAccessDoesNotChangeRelation(): void
    {
        $existingUser = new TestNullUser();
        $existingUser->setId("user-123");
        $existingUser->setName("John Doe");

        $existingCompany = new TestNullCompany();
        $existingCompany->setId("company-456");
        $existingCompany->setName("Acme Corp");

        $existingUser->setCompany($existingCompany);

        $locatorContainer = $this->createMock(
            EntityLocatorContainerInterface::class,
        );
        $entityLocator = $this->createMock(EntityLocatorInterface::class);

        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestNullUser::class, "user-123", [])
            ->willReturn($existingUser);

        $locatorContainer
            ->expects($this->once())
            ->method("getEntityLocatorByName")
            ->with("default")
            ->willReturn($entityLocator);

        $config = new Config(
            models: [
                new Model(
                    class: TestNullUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            getter: "getCompany",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestNullCompany::class,
                    type: "company",
                    attributes: [
                        new Attribute(
                            "id",
                            "string",
                            getter: "getId",
                            setter: "setId",
                        ),
                        new Attribute(
                            "name",
                            "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Update,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: new AccessConfig(authRequired: true),
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "name" => "Jane Doe",
        ];

        /** @var TestNullUser $result */
        $result = $mapper->map(
            "user",
            $source,
            ActionType::Update,
            "",
            "user-123",
        );

        $this->assertSame("Jane Doe", $result->getName());
        $this->assertNotNull($result->getCompany());
        $this->assertSame("company-456", $result->getCompany()->getId());
    }

    /**
     * Checks that WritableConfig with a null AccessConfig does not change behavior
     * on Create with an explicit null
     *
     * Проверяет, что WritableConfig с AccessConfig null не меняет поведение
     * при Create с явным null
     */
    public function testCreateWithNullRelationAndWritableConfigNullAccessWorks(): void
    {
        $locatorContainer = $this->createStub(
            EntityLocatorContainerInterface::class,
        );

        $config = new Config(
            models: [
                new Model(
                    class: TestNullUser::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestNullCompany::class,
                    type: "company",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "user-999",
            "name" => "Bob",
            "company" => null,
        ];

        /** @var TestNullUser $result */
        $result = $mapper->map("user", $source, ActionType::Create, "company");

        $this->assertSame("user-999", $result->getId());
        $this->assertSame("Bob", $result->getName());
        $this->assertNull($result->getCompany());
    }
}
