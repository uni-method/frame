<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\Exception;
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
use UniMethod\Frame\Mapping\{
    AttributeAccessor,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\{
    ActionNotConfiguredException,
    EntityInstantiationException,
    RelationTypeMismatchException,
};
use UniMethod\Frame\Persistence\EntityLocatorContainerInterface;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestCustomerDto,
    TestMixedConstruction,
    TestOrderDto,
    TestProductWithTags,
    TestProjectDto,
    TestProjectDtoMixed,
    TestServerConfig,
    TestTag,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingToObjectConstructorTest extends TestCase
{
    private MappingToObject $mapper;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProjectDto")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                readonly class TestProjectDto {
                    public function __construct(public string $server, public string $id) {}
                }

                class TestProjectDtoMixed {
                    public string $id;
                    public function __construct(public readonly string $server) {}
                }

                readonly class TestServerConfig {
                    public function __construct(
                        public string $host,
                        public int $port,
                        public bool $ssl = false,
                    ) {}
                    public function isSsl(): bool { return $this->ssl; }
                }

                class TestMixedConstruction {
                    private string $id;
                    private string $description;
                    public function __construct(
                        public readonly string $name,
                        public readonly int $age,
                    ) {}
                    public function setId(string $id): void { $this->id = $id; }
                    public function setDescription(string $description): void { $this->description = $description; }
                    public function getId(): string { return $this->id; }
                    public function getDescription(): string { return $this->description; }
                }

                readonly class TestOrderDto {
                    public function __construct(
                        public string $orderId,
                        public ?TestCustomerDto $customer,
                    ) {}
                }

                readonly class TestCustomerDto {
                    public function __construct(
                        public string $customerId,
                        public string $name,
                    ) {}
                }

                class TestProductWithTags {
                    /** @var array<TestTag> */
                    private array $tags = [];
                    public function __construct(
                        public readonly string $name,
                        public readonly float $price,
                    ) {}
                    public function addTag(TestTag $tag): void { $this->tags[] = $tag; }
                    /** @return array<TestTag> */
                    public function getTags(): array { return $this->tags; }
                }

                readonly class TestTag {
                    public function __construct(public string $name) {}
                }
            ');
        }
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
    }

    public function testMapWithSingleConstructorParameter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestProjectDto::class,
                    type: "project",
                    attributes: [
                        new Attribute(
                            "server",
                            "string",
                            constructorParam: true,
                        ),
                        new Attribute("id", "string", constructorParam: true),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "project")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["server" => "localhost", "id" => "123"];
        $result = $this->mapper->map("project", $source, ActionType::Create);

        $this->assertInstanceOf(TestProjectDto::class, $result);
        $this->assertSame("localhost", $result->server);
        $this->assertSame("123", $result->id);
    }

    public function testMapWithMixedConstructorAndSetters(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestProjectDtoMixed::class,
                    type: "project_mixed",
                    attributes: [
                        new Attribute(
                            "server",
                            "string",
                            constructorParam: true,
                        ),
                        new Attribute("id", "string", setter: "id"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "project_mixed")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["server" => "localhost", "id" => "123"];
        $result = $this->mapper->map(
            "project_mixed",
            $source,
            ActionType::Create,
        );

        $this->assertInstanceOf(TestProjectDtoMixed::class, $result);
        $this->assertSame("localhost", $result->server);
        $this->assertSame("123", $result->id);
    }

    public function testMapWithMultipleConstructorParameters(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestServerConfig::class,
                    type: "server_config",
                    attributes: [
                        new Attribute("host", "string", constructorParam: true),
                        new Attribute("port", "int", constructorParam: true),
                        new Attribute("ssl", "bool", constructorParam: true),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "server_config")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "host" => "example.com",
            "port" => 8080,
            "ssl" => true,
        ];
        $result = $this->mapper->map(
            "server_config",
            $source,
            ActionType::Create,
        );

        $this->assertInstanceOf(TestServerConfig::class, $result);
        $this->assertTrue($result->ssl);
    }

    public function testMapWithOptionalConstructorParameter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestServerConfig::class,
                    type: "server_config",
                    attributes: [
                        new Attribute("host", "string", constructorParam: true),
                        new Attribute("port", "int", constructorParam: true),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "server_config")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["host" => "example.com", "port" => 443];
        $result = $this->mapper->map(
            "server_config",
            $source,
            ActionType::Create,
        );

        $this->assertFalse($result->ssl);
    }

    public function testMapWithMixedConstructorAndNonConstructorParams(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestMixedConstruction::class,
                    type: "mixed",
                    attributes: [
                        new Attribute("name", "string", constructorParam: true),
                        new Attribute("age", "int", constructorParam: true),
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "description",
                            "string",
                            setter: "setDescription",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "mixed")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "John Doe",
            "age" => 30,
            "id" => "user-123",
            "description" => "Test user",
        ];

        $result = $this->mapper->map("mixed", $source, ActionType::Create);

        $this->assertSame("user-123", $result->getId());
        $this->assertSame("Test user", $result->getDescription());
    }

    public function testMapWithRelationInConstructor(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrderDto::class,
                    type: "order",
                    attributes: [
                        new Attribute(
                            "orderId",
                            "string",
                            constructorParam: true,
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            constructorParam: true,
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomerDto::class,
                    type: "customer",
                    attributes: [
                        new Attribute(
                            "customerId",
                            "string",
                            constructorParam: true,
                        ),
                        new Attribute("name", "string", constructorParam: true),
                    ],
                ),
            ],
            actions: [
                new Action(ActionType::Create, "order", [
                    "customer" => new WritableConfig(
                        mode: RelationWriteMode::Create,
                    ),
                ]),
            ],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "orderId" => "order-456",
            "customer" => (object) [
                "customerId" => "cust-789",
                "name" => "Jane Smith",
            ],
        ];

        $result = $this->mapper->map(
            "order",
            $source,
            ActionType::Create,
            "customer",
        );

        $this->assertSame("Jane Smith", $result->customer->name);
    }

    public function testMapWithConstructorAndRegularRelations(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestProductWithTags::class,
                    type: "product",
                    attributes: [
                        new Attribute("name", "string", constructorParam: true),
                        new Attribute("price", "float", constructorParam: true),
                    ],
                    relations: [
                        new Relation("tags", "oneToMany", "tag", add: "addTag"),
                    ],
                ),
                new Model(
                    class: TestTag::class,
                    type: "tag",
                    attributes: [
                        new Attribute("name", "string", constructorParam: true),
                    ],
                ),
            ],
            actions: [
                new Action(ActionType::Create, "product", [
                    "tags" => new WritableConfig(
                        mode: RelationWriteMode::Create,
                    ),
                ]),
            ],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Laptop",
            "price" => 999.99,
            "tags" => [
                (object) ["name" => "electronics"],
                (object) ["name" => "computers"],
            ],
        ];

        $result = $this->mapper->map(
            "product",
            $source,
            ActionType::Create,
            "tags",
        );

        $this->assertCount(2, $result->getTags());
    }

    public function testMapWithTypeCoercionInConstructor(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestServerConfig::class,
                    type: "server_config",
                    attributes: [
                        new Attribute("host", "string", constructorParam: true),
                        new Attribute("port", "int", constructorParam: true),
                        new Attribute("ssl", "bool", constructorParam: true),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "server_config")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "host" => "example.com",
            "port" => "9000",
            "ssl" => "1",
        ];
        /** @var TestServerConfig $result */
        $result = $this->mapper->map(
            "server_config",
            $source,
            ActionType::Create,
        );

        $this->assertTrue($result->isSsl());
    }

    public function testMapWithNullConstructorRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrderDto::class,
                    type: "order",
                    attributes: [
                        new Attribute(
                            "orderId",
                            "string",
                            constructorParam: true,
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            constructorParam: true,
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomerDto::class,
                    type: "customer",
                    attributes: [
                        new Attribute(
                            "customerId",
                            "string",
                            constructorParam: true,
                        ),
                        new Attribute("name", "string", constructorParam: true),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "order")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["orderId" => "order-456", "customer" => null];
        $result = $this->mapper->map("order", $source, ActionType::Create);

        $this->assertNull($result->customer);
    }

    /**
     * @throws ActionNotConfiguredException|RelationTypeMismatchException
     */
    public function testBackwardCompatibilityWithoutConstructorParams(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestMixedConstruction::class,
                    type: "mixed",
                    attributes: [
                        new Attribute("name", "string", setter: "name"),
                        new Attribute("age", "int", setter: "age"),
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "mixed")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(EntityInstantiationException::class);
        $source = (object) ["name" => "Test", "age" => 25, "id" => "id-123"];
        $this->mapper->map("mixed", $source, ActionType::Create);
    }
}
