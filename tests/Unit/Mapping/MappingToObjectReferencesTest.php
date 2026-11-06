<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\Stub;
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
    MissingEntityLocatorException,
    MissingIdAttributeException,
};
use UniMethod\Frame\Persistence\{
    EntityLocatorContainerInterface,
    EntityLocatorInterface,
    EntityNotFoundException,
    ListLocators,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestCustomer,
    TestOrder,
    TestOrderItem,
    TestProduct,
};
use UniMethod\Frame\Tests\Unit\Stub\{Post, RecordingLocator, Writer};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};
use UniMethod\Frame\Validator\Exception\RelationReferenceRequiresIdException;

final class MappingToObjectReferencesTest extends TestCase
{
    private EntityLocatorContainerInterface&Stub $locatorContainer;

    private EntityLocatorInterface&Stub $entityLocator;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestOrder")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestOrder {
                    private string $id;
                    private string $orderNumber;
                    private ?TestCustomer $customer = null;
                    private array $items = [];
                    private ?TestAddress $shippingAddress = null;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setOrderNumber(string $orderNumber): void { $this->orderNumber = $orderNumber; }
                    public function setCustomer(TestCustomer $customer): void { $this->customer = $customer; }
                    public function setItems(array $items): void { $this->items = $items; }
                    public function setShippingAddress(TestAddress $address): void
                    {
                        $this->shippingAddress = $address;
                    }
                    public function getId(): string { return $this->id; }
                    public function getOrderNumber(): string { return $this->orderNumber; }
                    public function getCustomer(): ?TestCustomer { return $this->customer; }
                    public function getItems(): array { return $this->items; }
                    public function getShippingAddress(): ?TestAddress { return $this->shippingAddress; }
                }

                class TestCustomer {
                    private string $id;
                    private string $name;
                    private string $email;
                    private array $orders = [];
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function setEmail(string $email): void { $this->email = $email; }
                    public function setOrders(array $orders): void { $this->orders = $orders; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getEmail(): string { return $this->email; }
                    public function getOrders(): array { return $this->orders; }
                }

                class TestOrderItem {
                    private string $id;
                    private string $productName;
                    private int $quantity;
                    private float $price;
                    private ?TestProduct $product = null;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setProductName(string $productName): void { $this->productName = $productName; }
                    public function setQuantity(int $quantity): void { $this->quantity = $quantity; }
                    public function setPrice(float $price): void { $this->price = $price; }
                    public function setProduct(TestProduct $product): void { $this->product = $product; }
                    public function getId(): string { return $this->id; }
                    public function getProductName(): string { return $this->productName; }
                    public function getQuantity(): int { return $this->quantity; }
                    public function getPrice(): float { return $this->price; }
                    public function getProduct(): ?TestProduct { return $this->product; }
                }

                class TestProduct {
                    private string $id;
                    private string $name;
                    private string $sku;
                    private float $price;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function setSku(string $sku): void { $this->sku = $sku; }
                    public function setPrice(float $price): void { $this->price = $price; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getSku(): string { return $this->sku; }
                    public function getPrice(): float { return $this->price; }
                }

                class TestAddress {
                    private string $id;
                    private string $street;
                    private string $city;
                    private string $country;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setStreet(string $street): void { $this->street = $street; }
                    public function setCity(string $city): void { $this->city = $city; }
                    public function setCountry(string $country): void { $this->country = $country; }
                    public function getId(): string { return $this->id; }
                    public function getStreet(): string { return $this->street; }
                    public function getCity(): string { return $this->city; }
                    public function getCountry(): string { return $this->country; }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->entityLocator = $this->createStub(EntityLocatorInterface::class);
        $this->locatorContainer = $this->createStub(
            EntityLocatorContainerInterface::class,
        );
        $this->locatorContainer
            ->method("getEntityLocatorByName")
            ->willReturnCallback(fn (): EntityLocatorInterface => $this->entityLocator);
    }

    public function testReferenceModeFetchesExistingEntity(): void
    {
        $entityLocator = $this->createMock(EntityLocatorInterface::class);
        $this->entityLocator = $entityLocator;

        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "orderNumber",
                            "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                        new Attribute("email", "string", setter: "setEmail"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $existingCustomer = new TestCustomer();
        $existingCustomer->setId("customer-123");
        $existingCustomer->setName("John Doe");
        $existingCustomer->setEmail("john@example.com");

        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(TestCustomer::class, "customer-123", [])
            ->willReturn($existingCustomer);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) ["id" => "customer-123"],
        ];

        /** @var TestOrder $result */
        $result = $mapper->map(
            "order",
            $source,
            ActionType::Create,
            "customer",
        );

        $this->assertSame($existingCustomer, $result->getCustomer());
        $this->assertSame("John Doe", $result->getCustomer()->getName());
    }

    public function testReferenceModeFetchesMultipleExistingEntities(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("orderNumber", "string"),
                    ],
                    relations: [
                        new Relation(
                            "items",
                            "oneToMany",
                            "orderItem",
                            setter: "setItems",
                        ),
                    ],
                ),
                new Model(
                    class: TestOrderItem::class,
                    type: "orderItem",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("productName", "string"),
                        new Attribute("quantity", "int"),
                        new Attribute("price", "float"),
                    ],
                    relations: [
                        new Relation(
                            "product",
                            "manyToOne",
                            "product",
                            setter: "setProduct",
                        ),
                    ],
                ),
                new Model(
                    class: TestProduct::class,
                    type: "product",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("name", "string"),
                        new Attribute("sku", "string"),
                        new Attribute("price", "float"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "orderItem", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "product", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "items.product" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $product1 = new TestProduct();
        $product1->setId("prod-1");
        $product1->setName("Laptop");
        $product1->setSku("LAP-001");
        $product1->setPrice(1200.0);

        $product2 = new TestProduct();
        $product2->setId("prod-2");
        $product2->setName("Mouse");
        $product2->setSku("MOU-001");
        $product2->setPrice(25.0);

        $this->entityLocator
            ->method("find")
            ->willReturnCallback(function (
                $class,
                $id
            ) use (
                $product1,
                $product2,
            ): ?TestProduct {
                return match ($id) {
                    "prod-1" => $product1,
                    "prod-2" => $product2,
                    default => null,
                };
            });

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "items" => [
                (object) [
                    "id" => "item-1",
                    "productName" => "Laptop",
                    "quantity" => 1,
                    "price" => 1200.0,
                    "product" => (object) ["id" => "prod-1"],
                ],
                (object) [
                    "id" => "item-2",
                    "productName" => "Mouse",
                    "quantity" => 2,
                    "price" => 25.0,
                    "product" => (object) ["id" => "prod-2"],
                ],
            ],
        ];

        /** @var TestOrder $result */
        $result = $mapper->map(
            "order",
            $source,
            ActionType::Create,
            "items,items.product",
        );

        $this->assertCount(2, $result->getItems());
        $this->assertSame($product1, $result->getItems()[0]->getProduct());
        $this->assertSame($product2, $result->getItems()[1]->getProduct());
        $this->assertSame(
            "Laptop",
            $result->getItems()[0]->getProduct()->getName(),
        );
    }

    public function testUpdateModeUpdatesExistingEntity(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "orderNumber",
                            "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                        new Attribute("email", "string", setter: "setEmail"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Update,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $existingOrder = new TestOrder();
        $existingOrder->setId("order-1");
        $existingOrder->setOrderNumber("OLD-001");

        $existingCustomer = new TestCustomer();
        $existingCustomer->setId("customer-123");
        $existingCustomer->setName("Old Name");
        $existingCustomer->setEmail("old@example.com");

        $this->entityLocator
            ->method("find")
            ->willReturnCallback(function (
                string $class,
                mixed $id
            ) use (
                $existingOrder,
                $existingCustomer,
            ): TestOrder|TestCustomer|null {
                if ($class === TestOrder::class && $id === "order-1") {
                    return $existingOrder;
                }

                if ($class === TestCustomer::class && $id === "customer-123") {
                    return $existingCustomer;
                }

                return null;
            });

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "orderNumber" => "ORD-001",
            "customer" => (object) [
                "id" => "customer-123",
                "name" => "Updated Name",
                "email" => "updated@example.com",
            ],
        ];

        /** @var TestOrder $result */
        $result = $mapper->map(
            "order",
            $source,
            ActionType::Update,
            "customer",
            "order-1",
        );

        $this->assertSame($existingOrder, $result);
        $this->assertSame("ORD-001", $result->getOrderNumber());
        $this->assertNotSame($existingCustomer, $result->getCustomer());
        $this->assertSame("Updated Name", $result->getCustomer()->getName());
        $this->assertSame(
            "updated@example.com",
            $result->getCustomer()->getEmail(),
        );
    }

    public function testReferenceModeThrowsExceptionWhenEntityNotFound(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("orderNumber", "string"),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $this->entityLocator->method("find")->willReturn(null);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) ["id" => "non-existent"],
        ];

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage(
            "Entity 'UniMethod\\Frame\\Tests\\Unit\\Mapping\\Stub\\TestCustomer' with id 'non-existent' not found",
        );

        $mapper->map("order", $source, ActionType::Create, "customer");
    }

    public function testUpdateModeThrowsExceptionWhenEntityNotFound(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("orderNumber", "string"),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                        new Attribute("email", "string", setter: "setEmail"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Update,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $existingOrder = new TestOrder();
        $existingOrder->setId("order-1");
        $existingOrder->setOrderNumber("OLD-001");

        $this->entityLocator
            ->method("find")
            ->willReturnCallback(function (
                string $class,
                mixed $id
            ) use (
                $existingOrder,
            ): ?TestOrder {
                if ($class === TestOrder::class && $id === "order-1") {
                    return $existingOrder;
                }

                // Customer with the 'missing' id is not found
                //
                // Customer with 'missing' id не найден
                return null;
            });

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) ["id" => "missing"],
        ];

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage(
            "Entity 'UniMethod\\Frame\\Tests\\Unit\\Mapping\\Stub\\TestCustomer' with id 'missing' not found",
        );

        $mapper->map(
            "order",
            $source,
            ActionType::Update,
            "customer",
            "order-1",
        );
    }

    public function testCreateModeIgnoresExistingEntities(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("orderNumber", "string"),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $existingCustomer = new TestCustomer();
        $existingCustomer->setId("customer-123");
        $existingCustomer->setName("Existing");

        $this->entityLocator->method("find")->willReturn($existingCustomer);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) [
                "id" => "customer-123",
                "name" => "New Customer",
            ],
        ];

        $result = $mapper->map(
            "order",
            $source,
            ActionType::Create,
            "customer",
        );

        $this->assertNotSame($existingCustomer, $result->getCustomer());
        $this->assertSame("New Customer", $result->getCustomer()->getName());
    }

    public function testReferenceWithoutEntityLocatorThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("orderNumber", "string"),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "order",
                    // no entityLocator
                    //
                    // нет entityLocator
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) ["id" => "customer-123"],
        ];

        $this->expectException(MissingEntityLocatorException::class);
        $this->expectExceptionMessage(
            "Reference mode for model 'customer' requires entityLocator configuration",
        );

        $mapper->map("order", $source, ActionType::Create, "customer");
    }

    /**
     * A Config assembled manually without an `id` attribute on the reference target: neither a stdClass in
     * the relation nor a TypeError, but a configuration error.
     *
     * Config, собранный вручную без атрибута `id` у цели ссылки: не stdClass в
     * связи и не TypeError, а ошибка конфигурации.
     */
    public function testTargetModelWithoutIdAttributeIsConfigurationError(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("orderNumber", "string"),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    // no id
                    //
                    // нет id
                    attributes: [],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
                new Action(ActionType::Read, "customer", entityLocator: "default"),
            ],
            locators: ["default" => RecordingLocator::class],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
            $config,
        );

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) ["id" => "customer-123"],
        ];

        $this->expectException(MissingIdAttributeException::class);
        $this->expectExceptionMessage("Model 'customer' has no 'id' attribute but is used as a reference target");

        $mapper->map("order", $source, ActionType::Create, "customer");
    }

    public function testReferencePassesIncludeToLocator(): void
    {
        $entityLocator = $this->createMock(EntityLocatorInterface::class);
        $this->entityLocator = $entityLocator;

        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "orderNumber",
                            "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                    relations: [new Relation("orders", "oneToMany", "order")],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "order", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $existingCustomer = new TestCustomer();
        $existingCustomer->setId("customer-123");
        $existingCustomer->setName("John");

        // Check that include is passed to the locator on Reference
        //
        // Проверяем что include передаётся в locator при Reference
        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(
                TestCustomer::class,
                "customer-123",
                // include for customer
                //
                // include для customer
                ["orders"],
            )
            ->willReturn($existingCustomer);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "customer" => (object) ["id" => "customer-123"],
        ];

        // Pass includeForLocator with the nested path customer.orders
        //
        // Передаём includeForLocator с вложенным путём customer.orders
        $result = $mapper->map(
            "order",
            $source,
            ActionType::Create,
            "customer",
            null,
            ["customer.orders"],
        );

        $this->assertSame($existingCustomer, $result->getCustomer());
    }

    public function testUpdatePassesIncludeToLocator(): void
    {
        $entityLocator = $this->createMock(EntityLocatorInterface::class);
        $this->entityLocator = $entityLocator;

        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "orderNumber",
                            "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                        new Relation(
                            "items",
                            "oneToMany",
                            "orderItem",
                            setter: "setItems",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
                new Model(
                    class: TestOrderItem::class,
                    type: "orderItem",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "orderItem", entityLocator: "default"),
                new Action(
                    ActionType::Update,
                    "order",
                    writable: [],
                    entityLocator: "default",
                ),
            ],
        );

        $existingOrder = new TestOrder();
        $existingOrder->setId("order-1");
        $existingOrder->setOrderNumber("OLD-001");

        // Check that include is passed to the locator on Update
        //
        // Проверяем что include передаётся в locator при Update
        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(
                TestOrder::class,
                "order-1",
                // include for order
                //
                // include для order
                ["customer", "items"],
            )
            ->willReturn($existingOrder);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "orderNumber" => "ORD-001",
        ];

        $result = $mapper->map(
            "order",
            $source,
            ActionType::Update,
            "",
            "order-1",
            ["customer", "items"],
        );

        $this->assertSame($existingOrder, $result);
        $this->assertSame("ORD-001", $result->getOrderNumber());
    }

    public function testNestedReferencePassesSubIncludeToLocator(): void
    {
        $entityLocator = $this->createMock(EntityLocatorInterface::class);
        $this->entityLocator = $entityLocator;

        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "orderNumber",
                            "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "items",
                            "oneToMany",
                            "orderItem",
                            setter: "setItems",
                        ),
                    ],
                ),
                new Model(
                    class: TestOrderItem::class,
                    type: "orderItem",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "productName",
                            "string",
                            setter: "setProductName",
                        ),
                        new Attribute("quantity", "int", setter: "setQuantity"),
                        new Attribute("price", "float", setter: "setPrice"),
                    ],
                    relations: [
                        new Relation(
                            "product",
                            "manyToOne",
                            "product",
                            setter: "setProduct",
                        ),
                    ],
                ),
                new Model(
                    class: TestProduct::class,
                    type: "product",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                        new Attribute("sku", "string", setter: "setSku"),
                        new Attribute("price", "float", setter: "setPrice"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "orderItem", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "product", entityLocator: "default"),
                new Action(
                    ActionType::Create,
                    "order",
                    writable: [
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "items.product" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
        );

        $product = new TestProduct();
        $product->setId("prod-1");
        $product->setName("Laptop");
        $product->setSku("LAP-001");
        $product->setPrice(1200.0);

        // Check that on Reference an empty include is passed for items.product
        // (because there are no nested paths after items.product)
        //
        // Проверяем что при Reference для items.product передаётся пустой include
        // (т.к. нет вложенных путей после items.product)
        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(
                TestProduct::class,
                "prod-1",
                // no nested paths
                //
                // нет вложенных путей
                [],
            )
            ->willReturn($product);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "order-1",
            "orderNumber" => "ORD-001",
            "items" => [
                (object) [
                    "id" => "item-1",
                    "productName" => "Laptop",
                    "quantity" => 1,
                    "price" => 1200.0,
                    "product" => (object) ["id" => "prod-1"],
                ],
            ],
        ];

        $result = $mapper->map(
            "order",
            $source,
            ActionType::Create,
            "items,items.product",
            null,
            ["items", "items.product"],
        );

        $this->assertSame($product, $result->getItems()[0]->getProduct());
    }

    public function testIncludeForLocatorWithEmptyArray(): void
    {
        $entityLocator = $this->createMock(EntityLocatorInterface::class);
        $this->entityLocator = $entityLocator;

        $config = new Config(
            models: [
                new Model(
                    class: TestOrder::class,
                    type: "order",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute(
                            "orderNumber",
                            "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            "customer",
                            "manyToOne",
                            "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: TestCustomer::class,
                    type: "customer",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    ActionType::Update,
                    "order",
                    writable: [],
                    entityLocator: "default",
                ),
            ],
        );

        $existingOrder = new TestOrder();
        $existingOrder->setId("order-1");
        $existingOrder->setOrderNumber("OLD-001");

        // Check that an empty includeForLocator works correctly
        //
        // Проверяем что пустой includeForLocator работает корректно
        $entityLocator
            ->expects($this->once())
            ->method("find")
            ->with(
                TestOrder::class,
                "order-1",
                // empty include
                //
                // пустой include
                [],
            )
            ->willReturn($existingOrder);

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->locatorContainer,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "orderNumber" => "ORD-001",
        ];

        // Do not pass includeForLocator - an empty array must be used by default
        //
        // Не передаём includeForLocator - должен использоваться пустой массив по умолчанию
        $result = $mapper->map(
            "order",
            $source,
            ActionType::Update,
            "",
            "order-1",
        );

        $this->assertSame("ORD-001", $result->getOrderNumber());
    }

    /**
     * The reference `{"id": null}` is not a reference: there is nothing to load →
     * RelationReferenceRequiresIdException (422).
     *
     * Ссылка `{"id": null}` — не ссылка: загрузить нечего →
     * RelationReferenceRequiresIdException (422).
     */
    public function testReferenceWithNullIdIsRejected(): void
    {
        $mapper = $this->postMapper(
            [new Action(type: ActionType::Read, model: "writer", entityLocator: "readLoc")],
            new ListLocators(["readLoc" => new RecordingLocator(new Writer(id: "5"))]),
        );

        $this->expectException(RelationReferenceRequiresIdException::class);

        $mapper->map(
            "post",
            (object) ["title" => "T", "author" => (object) ["id" => null]],
            ActionType::Create,
            "author",
        );
    }

    /**
     * A reference is resolved by the locator of the target model's read action regardless of
     * the order of its actions in the config.
     *
     * Ссылка резолвится локатором read-действия целевой модели независимо от
     * порядка её действий в конфиге.
     */
    public function testReferenceUsesReadLocatorRegardlessOfActionOrder(): void
    {
        foreach ([["delete", "read"], ["read", "delete"]] as $order) {
            $actions = [];
            foreach ($order as $type) {
                $actions[] = $type === "read"
                    ? new Action(type: ActionType::Read, model: "writer", entityLocator: "readLoc")
                    : new Action(type: ActionType::Delete, model: "writer", entityLocator: "deleteLoc");
            }

            $readLoc = new RecordingLocator(new Writer(id: "5"));
            $deleteLoc = new RecordingLocator(new Writer(id: "5"));

            $this->postMapper($actions, new ListLocators(["readLoc" => $readLoc, "deleteLoc" => $deleteLoc]))->map(
                "post",
                (object) ["title" => "T", "author" => (object) ["id" => "5"]],
                ActionType::Create,
                "author",
            );

            $label = "порядок действий writer: " . implode(",", $order);
            $this->assertSame(1, $readLoc->findCalls, $label);
            $this->assertSame(0, $deleteLoc->findCalls, $label);
        }
    }

    /**
     * The target model has no action with a locator → MissingEntityLocatorException:
     * the locator of the root model's action is not substituted.
     *
     * У целевой модели нет действия с локатором → MissingEntityLocatorException:
     * локатор действия корневой модели не подставляется.
     */
    public function testReferenceWithoutTargetLocatorDoesNotFallBackToRootLocator(): void
    {
        $mapper = $this->postMapper(
            [],
            new ListLocators(["loc" => new RecordingLocator(new Writer(id: "5"))]),
        );

        $this->expectException(MissingEntityLocatorException::class);

        $mapper->map(
            "post",
            (object) ["title" => "T", "author" => (object) ["id" => "5"]],
            ActionType::Create,
            "author",
        );
    }

    /**
     * A reference is loaded with the model locator (impl.models.<model>.entityLocator):
     * a read action of the target model is not needed, and if it exists the model locator
     * takes precedence.
     *
     * Ссылка грузится локатором модели (impl.models.<модель>.entityLocator):
     * read-действие целевой модели не нужно, а при его наличии локатор модели
     * важнее.
     */
    public function testReferenceUsesModelLocator(): void
    {
        foreach (
            [[], [new Action(type: ActionType::Read, model: "writer", entityLocator: "readLoc")]] as $writerActions
        ) {
            $modelLoc = new RecordingLocator(new Writer(id: "5"));
            $readLoc = new RecordingLocator(new Writer(id: "5"));

            $this->postMapper(
                $writerActions,
                new ListLocators(["modelLoc" => $modelLoc, "readLoc" => $readLoc]),
                writerLocator: "modelLoc",
            )->map("post", (object) ["title" => "T", "author" => (object) ["id" => "5"]], ActionType::Create, "author");

            self::assertSame(1, $modelLoc->findCalls);
            self::assertSame(0, $readLoc->findCalls);
        }
    }

    /**
     * Without its own locator and a read action — impl.defaultLocator.
     *
     * Без своего локатора и read-действия — impl.defaultLocator.
     */
    public function testReferenceUsesDefaultLocator(): void
    {
        $defaultLoc = new RecordingLocator(new Writer(id: "5"));

        $post = $this->postMapper(
            [],
            new ListLocators(["defaultLoc" => $defaultLoc]),
            defaultLocator: "defaultLoc",
        )->map("post", (object) ["title" => "T", "author" => (object) ["id" => "5"]], ActionType::Create, "author");

        self::assertInstanceOf(Post::class, $post);
        self::assertSame(1, $defaultLoc->findCalls);
        self::assertSame(Writer::class, $defaultLoc->lastClassName);
    }

    /**
     * post (create, writable author: reference, locator "loc") → author (writer).
     *
     * post (create, writable author: reference, локатор "loc") → author (writer).
     *
     * @param list<Action> $writerActions
     */
    private function postMapper(
        array $writerActions,
        ListLocators $locators,
        ?string $writerLocator = null,
        ?string $defaultLocator = null,
    ): MappingToObject {
        $config = new Config(
            models: [
                new Model(
                    class: Post::class,
                    type: "post",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "title", type: "string|null"),
                    ],
                    relations: [
                        new Relation(name: "author", type: Relation::MANY_TO_ONE, targetModel: "writer"),
                    ],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [
                        new Attribute(name: "id", type: "string|null"),
                        new Attribute(name: "name", type: "string|null"),
                    ],
                    entityLocator: $writerLocator,
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "post",
                    writable: ["author" => new WritableConfig(RelationWriteMode::Reference)],
                    entityLocator: "loc",
                ),
                ...$writerActions,
            ],
            defaultLocator: $defaultLocator,
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locators,
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        return $mapper;
    }
}
