<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use stdClass;
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
    AttributeNotFoundException,
    ModelNotFoundException,
    SetterNotCallableException,
    TypeMismatchException,
};
use UniMethod\Frame\Persistence\EntityLocatorContainerInterface;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestAllTypes,
    TestChild,
    TestInvalidSetter,
    TestListTypes,
    TestNullableTypes,
    TestParent,
    TestPrivateMethod,
    TestPrivateProperty,
    TestWithSetters,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingToObjectTest extends TestCase
{
    private MappingToObject $mapper;

    private function createTestClasses(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestAllTypes {
                    public string $name;
                    public int $age;
                    public float $price;
                    public bool $active;
                    public \DateTimeImmutable $createdAt;
                    public string $uuid;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestNullableTypes",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestNullableTypes {
                    public ?string $name = null;
                    public ?int $count = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestListTypes",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestListTypes {
                    /** @var array<string> */
                    public array $tags;
                    /** @var array<int> */
                    public array $scores;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestWithSetters",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestWithSetters {
                    private string $title;
                    private int $value;

                    public function getTitle(): string { return $this->title; }
                    public function setTitle(string $title): void { $this->title = $title; }
                    public function getValue(): int { return $this->value; }
                    public function setValue(int $value): void { $this->value = $value; }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestInvalidSetter",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestInvalidSetter {
                    public function setName(string $first, string $last): void {}
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestPrivateProperty",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestPrivateProperty {
                    private string $secret;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestPrivateMethod",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestPrivateMethod {
                    private function setSecret(string $value): void {}
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestChild")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestChild {
                    public string $title;
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestParent")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestParent {
                    public string $name;
                    /** @var array<TestChild> */
                    public array $children = [];

                    public function addChild(TestChild $child): void {
                        $this->children[] = $child;
                    }
                }
            ');
        }
    }

    public function testMapAllBasicTypes(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "all_types",
                    attributes: [
                        new Attribute("name", "string", setter: "name"),
                        new Attribute("age", "int", setter: "age"),
                        new Attribute("price", "float", setter: "price"),
                        new Attribute("active", "bool", setter: "active"),
                        new Attribute(
                            "createdAt",
                            "datetime",
                            setter: "createdAt",
                        ),
                        new Attribute("uuid", "uuid", setter: "uuid"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "all_types")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "John Doe",
            "age" => 30,
            "price" => 99.99,
            "active" => true,
            "createdAt" => "2025-01-15T10:30:00",
            "uuid" => "550e8400-e29b-41d4-a716-446655440000",
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("all_types", $source, ActionType::Create);

        $this->assertInstanceOf(TestAllTypes::class, $result);
        $this->assertSame("John Doe", $result->name);
        $this->assertSame(30, $result->age);
        $this->assertSame(99.99, $result->price);
        $this->assertTrue($result->active);
        $this->assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
        $this->assertSame(
            "550e8400-e29b-41d4-a716-446655440000",
            $result->uuid,
        );
    }

    public function testMapNullableTypes(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestNullableTypes::class,
                    type: "nullable",
                    attributes: [
                        new Attribute("name", "string|null", setter: "name"),
                        new Attribute("count", "int|null", setter: "count"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "nullable")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => null,
            "count" => null,
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestNullableTypes $result */
        $result = $this->mapper->map("nullable", $source, ActionType::Create);

        $this->assertInstanceOf(TestNullableTypes::class, $result);
        $this->assertNull($result->name);
        $this->assertNull($result->count);
    }

    public function testMapListTypes(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestListTypes::class,
                    type: "lists",
                    attributes: [
                        new Attribute("tags", "string[]", setter: "tags"),
                        new Attribute("scores", "int[]", setter: "scores"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "lists")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "tags" => ["php", "testing", "unit"],
            "scores" => [10, 20, 30],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestListTypes $result */
        $result = $this->mapper->map("lists", $source, ActionType::Create);

        $this->assertInstanceOf(TestListTypes::class, $result);
        $this->assertSame(["php", "testing", "unit"], $result->tags);
        $this->assertSame([10, 20, 30], $result->scores);
    }

    public function testMapWithMethodSetters(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestWithSetters::class,
                    type: "with_setters",
                    attributes: [
                        new Attribute("title", "string", setter: "setTitle"),
                        new Attribute("value", "int", setter: "setValue"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "with_setters")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "title" => "Test Title",
            "value" => 42,
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestWithSetters $result */
        $result = $this->mapper->map(
            "with_setters",
            $source,
            ActionType::Create,
        );

        $this->assertInstanceOf(TestWithSetters::class, $result);
        $this->assertSame("Test Title", $result->getTitle());
        $this->assertSame(42, $result->getValue());
    }

    public function testMapWithNullSetter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "null_setter",
                    attributes: [
                        // An attribute without a setter — must be ignored
                        //
                        // Атрибут без setter — должен игнорироваться
                        new Attribute("name", "string"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "null_setter")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Ignored",
        ];

        // Must work without errors, but the value will not be set
        //
        // Должно отработать без ошибок, но значение не установится
        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map(
            "null_setter",
            $source,
            ActionType::Create,
        );

        $this->assertInstanceOf(TestAllTypes::class, $result);
    }

    public function testAttributeWithSetterFalseIsNotMapped(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        // An attribute with setter: false must not be mapped
                        //
                        // Атрибут с setter: false не должен маппиться
                        new Attribute("age", "int", setter: false),
                        // An attribute with a regular setter must be mapped
                        //
                        // Атрибут с обычным setter должен маппиться
                        new Attribute("name", "string", setter: "name"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "age" => 30,
            "name" => "John",
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("test", $source, ActionType::Create);

        $this->assertInstanceOf(TestAllTypes::class, $result);
        $this->assertSame("John", $result->name);
        // age must not be set because setter: false
        //
        // age не должен быть установлен, так как setter: false
    }

    public function testMultipleAttributesWithSetterFalseAreNotMapped(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        new Attribute("name", "string", setter: "name"),
                        new Attribute("age", "int", setter: false),
                        new Attribute("price", "float", setter: false),
                        new Attribute("active", "bool", setter: "active"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Test",
            "age" => 25,
            "price" => 99.99,
            "active" => true,
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("test", $source, ActionType::Create);

        $this->assertInstanceOf(TestAllTypes::class, $result);
        $this->assertSame("Test", $result->name);
        $this->assertTrue($result->active);
        // age and price must not be set
        //
        // age и price не должны быть установлены
    }

    public function testThrowsModelNotFoundException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "existing_type",
                    attributes: [],
                ),
            ],
            actions: [new Action(ActionType::Create, "existing_type")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model not found: non_existing_type");

        $this->mapper->map(
            "non_existing_type",
            new stdClass(),
            ActionType::Create,
        );
    }

    public function testThrowsTypeMismatchExceptionForNonNullableNull(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [new Attribute("age", "int", setter: "age")],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "age" => null,
        ];

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage(
            "Type mismatch in 'test' 'age': expected int, got NULL",
        );

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testThrowsTypeMismatchExceptionForInvalidType(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [new Attribute("age", "int", setter: "age")],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "age" => "not a number",
        ];

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage(
            "Type mismatch in 'test' 'age': expected int, got 'not a number'",
        );

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testThrowsTypeMismatchExceptionForInvalidUuid(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [new Attribute("uuid", "uuid", setter: "uuid")],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "uuid" => "not-a-valid-uuid",
        ];

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage(
            "Type mismatch in 'test' 'uuid': expected uuid, got 'not-a-valid-uuid'",
        );

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testThrowsAttributeNotFoundException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        new Attribute(
                            "name",
                            "string",
                            setter: "nonExistentMethod",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Test",
        ];

        $this->expectException(AttributeNotFoundException::class);
        $this->expectExceptionMessage("No method/property 'nonExistentMethod'");

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testThrowsSetterNotCallableExceptionForInvalidParameterCount(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestInvalidSetter::class,
                    type: "test",
                    attributes: [
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Test",
        ];

        $this->expectException(SetterNotCallableException::class);
        $this->expectExceptionMessage(
            "Setter setName must take exactly 1 parameter",
        );

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testThrowsSetterNotCallableExceptionForPrivateProperty(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestPrivateProperty::class,
                    type: "test",
                    attributes: [
                        new Attribute("secret", "string", setter: "secret"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "secret" => "confidential",
        ];

        $this->expectException(SetterNotCallableException::class);
        $this->expectExceptionMessage("Property secret is not public");

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testMapWithTypeAliases(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        new Attribute("age", "integer", setter: "age"),
                        new Attribute("price", "double", setter: "price"),
                        new Attribute("active", "boolean", setter: "active"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "age" => 25,
            "price" => 15.5,
            "active" => false,
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("test", $source, ActionType::Create);

        $this->assertSame(25, $result->age);
        $this->assertSame(15.5, $result->price);
        $this->assertFalse($result->active);
    }

    public function testMapWithTypeCoercion(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        new Attribute("age", "int", setter: "age"),
                        new Attribute("price", "float", setter: "price"),
                        new Attribute("active", "bool", setter: "active"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            // string → int
            //
            // строка → int
            "age" => "42",
            // string → float
            //
            // строка → float
            "price" => "19.99",
            // string → bool
            //
            // строка → bool
            "active" => "1",
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("test", $source, ActionType::Create);

        $this->assertSame(42, $result->age);
        $this->assertSame(19.99, $result->price);
        $this->assertTrue($result->active);
    }

    public function testMapWithDateTimeFromString(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        new Attribute(
                            "createdAt",
                            "\DateTimeImmutable",
                            setter: "createdAt",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "createdAt" => "2025-10-16T12:00:00",
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("test", $source, ActionType::Create);

        $this->assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
        $this->assertSame("2025-10-16", $result->createdAt->format("Y-m-d"));
    }

    public function testMapWithDateTimeObject(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestAllTypes::class,
                    type: "test",
                    attributes: [
                        new Attribute(
                            "createdAt",
                            "datetime",
                            setter: "createdAt",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "createdAt" => new DateTimeImmutable("2025-10-16 12:00:00"),
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestAllTypes $result */
        $result = $this->mapper->map("test", $source, ActionType::Create);

        $this->assertInstanceOf(DateTimeImmutable::class, $result->createdAt);
        $this->assertSame("2025-10-16", $result->createdAt->format("Y-m-d"));
    }

    public function testThrowsSetterNotCallableExceptionForPrivateMethod(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestPrivateMethod::class,
                    type: "test",
                    attributes: [
                        new Attribute("secret", "string", setter: "setSecret"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "test")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "secret" => "confidential",
        ];

        $this->expectException(SetterNotCallableException::class);
        $this->expectExceptionMessageMatches(
            "/Call to (private|protected) method/",
        );

        $this->mapper->map("test", $source, ActionType::Create);
    }

    public function testRelationWithSetterFalseAndAddFalseIsNotMapped(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestParent::class,
                    type: "parent",
                    attributes: [
                        new Attribute("name", "string", setter: "name"),
                    ],
                    relations: [
                        // A relation with setter: false and add: false must not be mapped
                        //
                        // Связь с setter: false и add: false не должна маппиться
                        new Relation(
                            name: "children",
                            type: "oneToMany",
                            targetModel: "child",
                            setter: false,
                            add: false,
                        ),
                    ],
                ),
                new Model(
                    class: TestChild::class,
                    type: "child",
                    attributes: [
                        new Attribute("title", "string", setter: "title"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "parent",
                    writable: [
                        "children" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Parent",
            "children" => [
                (object) ["title" => "Child 1"],
                (object) ["title" => "Child 2"],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestParent $result */
        $result = $this->mapper->map(
            "parent",
            $source,
            ActionType::Create,
            "children",
        );

        $this->assertInstanceOf(TestParent::class, $result);
        $this->assertSame("Parent", $result->name);
        // children must not be set because setter: false and add: false
        //
        // children не должны быть установлены, так как setter: false и add: false
        $this->assertEmpty($result->children);
    }

    public function testRelationWithSetterTrueButAddFalseUsesSetterForCollection(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestParent::class,
                    type: "parent",
                    attributes: [
                        new Attribute("name", "string", setter: "name"),
                    ],
                    relations: [
                        // A relation with setter: 'children' and add: false - the setter must be used
                        //
                        // Связь с setter: 'children' и add: false - должен использоваться setter
                        new Relation(
                            name: "children",
                            type: "oneToMany",
                            targetModel: "child",
                            setter: "children",
                            add: false,
                        ),
                    ],
                ),
                new Model(
                    class: TestChild::class,
                    type: "child",
                    attributes: [
                        new Attribute("title", "string", setter: "title"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "parent",
                    writable: [
                        "children" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Parent",
            "children" => [
                (object) ["title" => "Child 1"],
                (object) ["title" => "Child 2"],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestParent $result */
        $result = $this->mapper->map(
            "parent",
            $source,
            ActionType::Create,
            "children",
        );

        $this->assertInstanceOf(TestParent::class, $result);
        $this->assertSame("Parent", $result->name);
        // children must be set via the setter
        //
        // children должны быть установлены через setter
        $this->assertCount(2, $result->children);
        $this->assertSame("Child 1", $result->children[0]->title);
        $this->assertSame("Child 2", $result->children[1]->title);
    }

    public function testRelationWithSetterFalseButAddTrueUsesAdderForCollection(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestParent::class,
                    type: "parent",
                    attributes: [
                        new Attribute("name", "string", setter: "name"),
                    ],
                    relations: [
                        // A relation with setter: false and add: 'addChild' - the adder must be used
                        //
                        // Связь с setter: false и add: 'addChild' - должен использоваться adder
                        new Relation(
                            name: "children",
                            type: "oneToMany",
                            targetModel: "child",
                            setter: false,
                            add: "addChild",
                        ),
                    ],
                ),
                new Model(
                    class: TestChild::class,
                    type: "child",
                    attributes: [
                        new Attribute("title", "string", setter: "title"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "parent",
                    writable: [
                        "children" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Parent",
            "children" => [
                (object) ["title" => "Child 1"],
                (object) ["title" => "Child 2"],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestParent $result */
        $result = $this->mapper->map(
            "parent",
            $source,
            ActionType::Create,
            "children",
        );

        $this->assertInstanceOf(TestParent::class, $result);
        $this->assertSame("Parent", $result->name);
        // children must be added via addChild
        //
        // children должны быть добавлены через addChild
        $this->assertCount(2, $result->children);
        $this->assertSame("Child 1", $result->children[0]->title);
        $this->assertSame("Child 2", $result->children[1]->title);
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->createTestClasses();

        $this->mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
    }
}
