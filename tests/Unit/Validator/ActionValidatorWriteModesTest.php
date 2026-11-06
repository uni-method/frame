<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    Relation,
    RelationWriteMode,
};
use UniMethod\Frame\Config\{AccessConfig, WritableConfig};
use UniMethod\Frame\Persistence\{EntityLocatorInterface, LocatorNotConfiguredException};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\{
    RelationModeNotAllowedException,
    RelationReferenceRequiresIdException,
    UnwritableRelationException,
};

/**
 * Comprehensive tests for validating various write modes (Reference, Create, Update)
 *
 * Comprehensive тесты для валидации различных режимов записи (Reference, Create, Update)
 */
final class ActionValidatorWriteModesTest extends TestCase
{
    private ActionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ActionValidator(new TreeBuilder());

        // Create a configuration with various modes
        //
        // Создаем конфигурацию с различными режимами
        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "orderNumber",
                            type: "string",
                            getter: "getOrderNumber",
                            setter: "setOrderNumber",
                        ),
                        new Attribute(
                            name: "total",
                            type: "float",
                            getter: "getTotal",
                            setter: "setTotal",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "customer",
                            type: "manyToOne",
                            targetModel: "customer",
                            getter: "getCustomer",
                            setter: "setCustomer",
                        ),
                        new Relation(
                            name: "items",
                            type: "oneToMany",
                            targetModel: "orderItem",
                            getter: "getItems",
                            setter: "setItems",
                        ),
                        new Relation(
                            name: "shippingAddress",
                            type: "oneToOne",
                            targetModel: "address",
                            getter: "getShippingAddress",
                            setter: "setShippingAddress",
                        ),
                        new Relation(
                            name: "paymentMethod",
                            type: "manyToOne",
                            targetModel: "paymentMethod",
                            getter: "getPaymentMethod",
                            setter: "setPaymentMethod",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Customer",
                    type: "customer",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                        new Attribute(
                            name: "email",
                            type: "string",
                            getter: "getEmail",
                            setter: "setEmail",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "orders",
                            type: "oneToMany",
                            targetModel: "order",
                            getter: "getOrders",
                            setter: "setOrders",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\OrderItem",
                    type: "orderItem",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "quantity",
                            type: "int",
                            getter: "getQuantity",
                            setter: "setQuantity",
                        ),
                        new Attribute(
                            name: "price",
                            type: "float",
                            getter: "getPrice",
                            setter: "setPrice",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "product",
                            type: "manyToOne",
                            targetModel: "product",
                            getter: "getProduct",
                            setter: "setProduct",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Product",
                    type: "product",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                        new Attribute(
                            name: "sku",
                            type: "string",
                            getter: "getSku",
                        ),
                    ],
                    relations: [],
                ),
                new Model(
                    class: "App\\Address",
                    type: "address",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "street",
                            type: "string",
                            getter: "getStreet",
                            setter: "setStreet",
                        ),
                        new Attribute(
                            name: "city",
                            type: "string",
                            getter: "getCity",
                            setter: "setCity",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "country",
                            type: "manyToOne",
                            targetModel: "country",
                            getter: "getCountry",
                            setter: "setCountry",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Country",
                    type: "country",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                        ),
                        new Attribute(
                            name: "code",
                            type: "string",
                            getter: "getCode",
                        ),
                    ],
                    relations: [],
                ),
                new Model(
                    class: "App\\PaymentMethod",
                    type: "paymentMethod",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "type",
                            type: "string",
                            getter: "getType",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "order", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "orderItem", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "product", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "address", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "country", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "paymentMethod", entityLocator: "default"),
                // CREATE with different modes
                //
                // CREATE с разными режимами
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "items.product" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                        "shippingAddress" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "shippingAddress.country" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                        "paymentMethod" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
                // UPDATE with different modes
                //
                // UPDATE с разными режимами
                new Action(
                    type: ActionType::Update,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "items.product" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                        "shippingAddress" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                        "paymentMethod" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
                // CREATE with the legacy format (all modes are allowed)
                //
                // CREATE с legacy format (все режимы разрешены)
                new Action(
                    type: ActionType::Create,
                    model: "customer",
                    writable: [
                        "orders" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
            locators: [
                "default" => EntityLocatorInterface::class,
            ],
        );

        $this->validator->setConfig($config);
    }

    public function testValidReferenceModeSingleRelation(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-001",
            "total" => 100.0,
            // only id = Reference
            //
            // только id = Reference
            "customer" => (object) ["id" => "customer-123"],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testValidReferenceModeManyRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-002",
            "total" => 200.0,
            "customer" => (object) ["id" => "customer-123"],
            "paymentMethod" => (object) ["id" => "pm-456"],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testValidReferenceInCollection(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-003",
            "items" => [
                (object) [
                    "quantity" => 2,
                    "price" => 50.0,
                    "product" => (object) ["id" => "prod-1"], // Reference
                ],
                (object) [
                    "quantity" => 1,
                    "price" => 100.0,
                    "product" => (object) ["id" => "prod-2"], // Reference
                ],
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testReferenceWithUndefinedLocatorThrowsException(): void
    {
        // Create a config without locators
        //
        // Создаем конфиг без locators
        $config = new Config(
            models: [
                new Model(
                    class: "App\\User",
                    type: "user",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            setter: "setName",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "company",
                            type: "manyToOne",
                            targetModel: "company",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Company",
                    type: "company",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(name: "name", type: "string"),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "company", entityLocator: "undefined-locator"),
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "undefined-locator",
                ),
            ],
            // Empty
            //
            // Пустой
            locators: [],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) [
            "name" => "John",
            "company" => (object) ["id" => "company-1"],
        ];

        $this->expectException(LocatorNotConfiguredException::class);

        $validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testValidCreateModeSingleRelation(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-005",
            "items" => [
                (object) [
                    "id" => "items-123",
                    "quantity" => 1,
                    "price" => 50.0,
                    // no id = Create
                    //
                    // нет id = Create
                ],
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testValidCreateModeWithNestedData(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-006",
            "shippingAddress" => (object) [
                "street" => "456 Oak Ave",
                "city" => "San Francisco",
                // Reference inside Create
                //
                // Reference внутри Create
                "country" => (object) ["id" => "country-us"],
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testValidCreateModeInCollection(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-007",
            "items" => [
                (object) [
                    "quantity" => 2,
                    "price" => 25.0,
                    "product" => (object) ["id" => "prod-1"],
                ],
                (object) [
                    "quantity" => 3,
                    "price" => 15.0,
                    "product" => (object) ["id" => "prod-2"],
                ],
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testValidUpdateModeInCollection(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-009",
            "items" => [
                (object) [
                    "id" => "item-1",
                    "quantity" => 5, // Update
                    "price" => 30.0,
                ],
                (object) [
                    "id" => "item-2",
                    "quantity" => 2, // Update
                    "price" => 15.0,
                ],
            ],
        ];

        $this->validator->validateStd(ActionType::Update, "order", $data);
    }

    public function testMixedModesInSingleAction(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-012",
            "customer" => (object) ["id" => "customer-123"], // Reference
            "items" => [
                (object) [
                    "quantity" => 1,
                    "price" => 100.0,
                    // Create with Reference
                    //
                    // Create со ссылкой (Reference)
                    "product" => (object) ["id" => "prod-1"],
                ],
            ],
            "shippingAddress" => (object) [
                "street" => "789 Elm St",
                "city" => "Austin",
                // Create with Reference
                //
                // Create со ссылкой (Reference)
                "country" => (object) ["id" => "country-us"],
            ],
            "paymentMethod" => (object) ["id" => "pm-card"], // Reference
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testMixedModesInCollection(): void
    {
        $this->expectNotToPerformAssertions();

        // A collection with different modes in its elements
        //
        // Коллекция с разными режимами в элементах
        $data = (object) [
            "id" => "object-1",
            "orderNumber" => "ORD-013",
            "items" => [
                (object) ["id" => "item-1", "quantity" => 5], // Update
                (object) ["quantity" => 2, "price" => 20.0], // Create
                (object) ["id" => "item-3", "quantity" => 3], // Update
            ],
        ];

        // The validator will detect a mixed mode and choose the priority one (Update > Create > Reference)
        //
        // Валидатор определит смешанный режим и выберет приоритетный (Update > Create > Reference)
        $this->validator->validateStd(ActionType::Update, "order", $data);
    }

    public function testMixedModesDeepNesting(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-014",
            "customer" => (object) [
                "id" => "customer-123",
                "name" => "Updated Name", // Update
            ],
            "items" => [
                (object) [
                    "quantity" => 1,
                    "price" => 50.0,
                    // Create with Reference nested
                    //
                    // Create с вложенной ссылкой (Reference)
                    "product" => (object) ["id" => "prod-1"],
                ],
            ],
        ];

        $this->validator->validateStd(ActionType::Update, "order", $data);
    }

    public function testCreateModeNotAllowedThrowsException(): void
    {
        // In the CREATE action only Reference is allowed for customer
        //
        // В CREATE action для customer разрешен только Reference
        $data = (object) [
            "orderNumber" => "ORD-015",
            "customer" => (object) [
                // Create mode, but not allowed
                //
                // Create mode, но не разрешен
                "name" => "New Customer",
                "email" => "new@example.com",
            ],
        ];

        $this->expectException(RelationModeNotAllowedException::class);
        $this->expectExceptionMessage(
            "Relation 'customer' in model 'order' does not allow mode 'create' for action 'create'.",
        );

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testUpdateModeNotAllowedThrowsException(): void
    {
        // In the CREATE action only Reference is allowed for customer
        //
        // В CREATE action для customer разрешен только Reference
        $data = (object) [
            "orderNumber" => "ORD-016",
            "customer" => (object) [
                "id" => "customer-123",
                // Update mode, but not allowed in the CREATE action
                //
                // Update mode, но не разрешен в CREATE action
                "name" => "Updated Name",
            ],
        ];

        $this->expectException(RelationModeNotAllowedException::class);
        $this->expectExceptionMessage(
            "Relation 'customer' in model 'order' does not allow mode 'create' for action 'create'.",
        );

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testReferenceModeNotAllowedInNestedRelation(): void
    {
        // items.product must be only Reference in CREATE, but if we try Create
        //
        // items.product должен быть только Reference в CREATE, но если попытаться Create
        $data = (object) [
            "orderNumber" => "ORD-017",
            "items" => [
                (object) [
                    "quantity" => 1,
                    "price" => 50.0,
                    "product" => (object) [
                        // Create mode for product
                        //
                        // Create mode для product
                        "name" => "New Product",
                        "sku" => "SKU-001",
                    ],
                ],
            ],
        ];

        $this->expectException(RelationModeNotAllowedException::class);
        $this->expectExceptionMessage(
            "Relation 'items.product' in model 'orderItem' does not allow mode 'create' for action 'create'.",
        );

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testLegacyFormatAllowsAllModes(): void
    {
        $this->expectNotToPerformAssertions();

        // The customer action uses the legacy format (all modes are allowed)
        //
        // Customer action использует legacy format (все режимы разрешены)
        $data = (object) [
            "name" => "Test Customer",
            "orders" => [
                (object) ["id" => "order-1"], // Reference - OK
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "customer", $data);
    }

    public function testEcommerceOrderCreationScenario(): void
    {
        $this->expectNotToPerformAssertions();

        // A realistic order creation scenario
        //
        // Реалистичный сценарий создания заказа
        $data = (object) [
            "orderNumber" => "ORD-2025-001",
            "total" => 299.99,
            // Reference to an existing one
            //
            // Reference к существующему
            "customer" => (object) ["id" => "customer-456"],
            "items" => [
                (object) [
                    "quantity" => 2,
                    "price" => 99.99,
                    "product" => (object) ["id" => "prod-laptop"], // Reference
                ],
                (object) [
                    "quantity" => 1,
                    "price" => 49.99,
                    "product" => (object) ["id" => "prod-mouse"], // Reference
                ],
            ],
            "shippingAddress" => (object) [
                "street" => "123 Main St",
                "city" => "New York",
                "country" => (object) ["id" => "country-us"], // Reference
            ],
            "paymentMethod" => (object) ["id" => "pm-credit-card"], // Reference
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testEmptyCollectionIsValid(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-018",
            // An empty collection is OK
            //
            // Пустая коллекция OK
            "items" => [],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testNullRelationIsValid(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-019",
            // null is OK for an optional relation
            //
            // null OK для необязательной связи
            "shippingAddress" => null,
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testOnlyIdFieldIsReference(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-020",
            // ONLY id = Reference
            //
            // ТОЛЬКО id = Reference
            "customer" => (object) ["id" => "customer-999"],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testIdPlusOneFieldIsUpdate(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-021",
            "customer" => (object) [
                "id" => "customer-999",
                // id + 1 field = Update
                //
                // id + 1 поле = Update
                "name" => "Name",
            ],
        ];

        $this->validator->validateStd(ActionType::Update, "order", $data);
    }

    public function testNoIdIsCreate(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "orderNumber" => "ORD-022",
            "items" => [
                (object) [
                    // no id = Create
                    //
                    // нет id = Create
                    "quantity" => 1,
                    "price" => 10.0,
                    "product" => (object) ["id" => "prod-1"],
                ],
            ],
        ];

        $this->validator->validateStd(ActionType::Create, "order", $data);
    }

    // =============== WritableConfig with AccessConfig ===============
    //
    // =============== WritableConfig с AccessConfig ===============

    public function testWritableConfigWithAccessConfigReferenceModeValidatesLikeWithout(): void
    {
        $this->expectNotToPerformAssertions();

        // AccessConfig inside WritableConfig must not affect the validation logic
        //
        // AccessConfig внутри WritableConfig не должен влиять на логику валидации
        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "orderNumber",
                            type: "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "customer",
                            type: "manyToOne",
                            targetModel: "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Customer",
                    type: "customer",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            setter: "setName",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
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
            locators: ["default" => EntityLocatorInterface::class],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) [
            "orderNumber" => "ORD-100",
            "customer" => (object) ["id" => "customer-1"],
        ];

        $validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testWritableConfigWithAccessConfigCreateModeValidatesLikeWithout(): void
    {
        $this->expectNotToPerformAssertions();

        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "orderNumber",
                            type: "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "items",
                            type: "oneToMany",
                            targetModel: "orderItem",
                            setter: "setItems",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\OrderItem",
                    type: "orderItem",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "quantity",
                            type: "int",
                            setter: "setQuantity",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                            access: new AccessConfig(authRequired: true),
                        ),
                    ],
                ),
            ],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) [
            "orderNumber" => "ORD-101",
            "items" => [(object) ["quantity" => 2]],
        ];

        $validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testWritableConfigMixedWithAndWithoutAccessConfigValidates(): void
    {
        $this->expectNotToPerformAssertions();

        // One WritableConfig with AccessConfig, the other without — both work the same
        //
        // Один WritableConfig с AccessConfig, другой без — оба работают одинаково
        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "orderNumber",
                            type: "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "customer",
                            type: "manyToOne",
                            targetModel: "customer",
                            setter: "setCustomer",
                        ),
                        new Relation(
                            name: "items",
                            type: "oneToMany",
                            targetModel: "orderItem",
                            setter: "setItems",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Customer",
                    type: "customer",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            setter: "setName",
                        ),
                    ],
                    relations: [],
                ),
                new Model(
                    class: "App\\OrderItem",
                    type: "orderItem",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "quantity",
                            type: "int",
                            setter: "setQuantity",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(type: ActionType::Read, model: "orderItem", entityLocator: "default"),
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: new AccessConfig(
                                authRequired: true,
                                roles: ["admin"],
                            ),
                        ),
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) [
            "orderNumber" => "ORD-102",
            "customer" => (object) ["id" => "customer-1"],
            "items" => [(object) ["quantity" => 3]],
        ];

        $validator->validateStd(ActionType::Create, "order", $data);
    }

    public function testWritableConfigWithAccessConfigWrongModeStillThrows(): void
    {
        // AccessConfig does not change the fact that a wrong mode throws an exception
        //
        // AccessConfig не меняет то, что неправильный режим бросает исключение
        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "orderNumber",
                            type: "string",
                            setter: "setOrderNumber",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "customer",
                            type: "manyToOne",
                            targetModel: "customer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Customer",
                    type: "customer",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            setter: "setName",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
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
            locators: ["default" => EntityLocatorInterface::class],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        // Pass Create data (several fields), but the config allows only Reference
        //
        // Передаём Create-данные (несколько полей), но конфиг разрешает только Reference
        $data = (object) [
            "orderNumber" => "ORD-103",
            "customer" => (object) ["id" => "customer-1", "name" => "Acme"],
        ];

        $this->expectException(RelationModeNotAllowedException::class);
        $validator->validateStd(ActionType::Create, "order", $data);
    }

    /**
     * A nested path is written only if it is declared in writable explicitly:
     * `customer` in writable does not open `customer.orders`.
     *
     * Вложенный путь пишется, только если объявлен в writable явно:
     * `customer` в writable не открывает `customer.orders`.
     */
    public function testNestedPathIsNotWritableThroughParent(): void
    {
        $data = (object) [
            "orderNumber" => "ORD-030",
            "customer" => (object) [
                "name" => "N",
                "orders" => [(object) ["id" => "order-1"]],
            ],
        ];

        $this->expectException(UnwritableRelationException::class);
        $this->expectExceptionMessageMatches("/Relation 'customer\\.orders'/");

        $this->validator->validateStd(ActionType::Update, "order", $data, "customer,customer.orders");
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
        $data = (object) [
            "orderNumber" => "ORD-031",
            "customer" => (object) ["id" => null],
        ];

        $this->expectException(RelationReferenceRequiresIdException::class);

        $this->validator->validateStd(ActionType::Create, "order", $data, "customer");
    }
}
