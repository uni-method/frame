<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Config, Model};
use UniMethod\Frame\Mapping\{
    AttributeAccessor,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\{
    TypeMismatchException,
};
use UniMethod\Frame\Persistence\EntityLocatorContainerInterface;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestArrayTypes,
    TestArrayWithTypeCoercion,
    TestConstructorWithArray,
    TestEmptyArrays,
    TestNullableArray,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingToObjectArrayTest extends TestCase
{
    private MappingToObject $mapper;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestArrayTypes")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                use DateTimeInterface;

                class TestArrayTypes {
                    /** @var array<string> */
                    public array $tags = [];
                    /** @var array<int> */
                    public array $scores = [];
                    /** @var array<float> */
                    public array $prices = [];
                    /** @var array<bool> */
                    public array $flags = [];
                    /** @var array<DateTimeInterface> */
                    public array $dates = [];
                    /** @var array<string> */
                    public array $uuids = [];
                }

                class TestNullableArray {
                    /** @var array<string>|null */
                    public ?array $tags = null;
                    /** @var array<int>|null */
                    public ?array $numbers = null;
                }

                class TestEmptyArrays {
                    /** @var array<string> */
                    public array $items = [];
                }

                class TestArrayWithTypeCoercion {
                    /** @var array<string> */
                    public array $stringArray = [];
                    /** @var array<int> */
                    public array $intArray = [];
                    /** @var array<bool> */
                    public array $boolArray = [];
                }

                readonly class TestConstructorWithArray {
                    /**
                     * @param array<string> $tags
                     * @param array<int> $scores
                     */
                    public function __construct(
                        public string $name,
                        public array $tags,
                        public array $scores,
                    ) {}
                }

                class TestMixedArrays {
                    /** @var array<string> */
                    public array $data = [];
                }
            ');
        }
    }

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

    // ===== STRING ARRAYS =====
    //
    // ===== МАССИВЫ СТРОК =====

    public function testMapStringArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("tags", "string[]", setter: "tags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["tags" => ["php", "testing", "arrays"]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame(["php", "testing", "arrays"], $result->tags);
    }

    public function testMapStringArrayWithTypeCoercion(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute(
                            "stringArray",
                            "string[]",
                            setter: "stringArray",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        // Mixed types that can be converted to strings
        //
        // Смешанные типы, которые можно привести к строкам
        $source = (object) ["stringArray" => [1, 2.5, true, false, "text"]];
        $result = $this->mapper->map("coercion", $source, ActionType::Create);

        $this->assertSame(["1", "2.5", "1", "", "text"], $result->stringArray);
    }

    public function testMapEmptyStringArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestEmptyArrays::class,
                    type: "empty",
                    attributes: [
                        new Attribute("items", "string[]", setter: "items"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "empty")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["items" => []];
        $result = $this->mapper->map("empty", $source, ActionType::Create);

        $this->assertSame([], $result->items);
    }

    // ===== INT ARRAYS =====
    //
    // ===== МАССИВЫ INT =====

    public function testMapIntArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("scores", "int[]", setter: "scores"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["scores" => [10, 20, 30, 40]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame([10, 20, 30, 40], $result->scores);
    }

    public function testMapIntArrayWithStringCoercion(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute("intArray", "int[]", setter: "intArray"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["intArray" => ["10", "20", "30"]];
        $result = $this->mapper->map("coercion", $source, ActionType::Create);

        $this->assertSame([10, 20, 30], $result->intArray);
    }

    public function testMapIntArrayWithFloatCoercion(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute("intArray", "int[]", setter: "intArray"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        // Whole number floats should convert
        //
        // Целочисленные float должны конвертироваться
        $source = (object) ["intArray" => [10.0, 20.0, 30.0]];
        $result = $this->mapper->map("coercion", $source, ActionType::Create);

        $this->assertSame([10, 20, 30], $result->intArray);
    }

    public function testMapIntArrayWithInvalidValueThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute("intArray", "int[]", setter: "intArray"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(TypeMismatchException::class);

        $source = (object) ["intArray" => [10, 20, "not-a-number"]];
        $this->mapper->map("coercion", $source, ActionType::Create);
    }

    public function testMapIntArrayWithFloatDecimalsThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute("intArray", "int[]", setter: "intArray"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(TypeMismatchException::class);

        // Floats with decimals should NOT convert
        //
        // Float с дробной частью НЕ должны конвертироваться
        $source = (object) ["intArray" => [10, 20.5, 30]];
        $this->mapper->map("coercion", $source, ActionType::Create);
    }

    // ===== FLOAT ARRAYS =====
    //
    // ===== МАССИВЫ FLOAT =====

    public function testMapFloatArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("prices", "float[]", setter: "prices"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["prices" => [9.99, 19.99, 29.99]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame([9.99, 19.99, 29.99], $result->prices);
    }

    public function testMapFloatArrayWithMixedTypes(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("prices", "float[]", setter: "prices"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["prices" => [10, 20.5, "30.75", "40"]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame([10.0, 20.5, 30.75, 40.0], $result->prices);
    }

    // ===== BOOL ARRAYS =====
    //
    // ===== МАССИВЫ BOOL =====

    public function testMapBoolArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("flags", "bool[]", setter: "flags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["flags" => [true, false, true, true]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame([true, false, true, true], $result->flags);
    }

    public function testMapBoolArrayWithStringCoercion(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute(
                            "boolArray",
                            "bool[]",
                            setter: "boolArray",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "boolArray" => [
                "true",
                "1",
                "yes",
                "on",
                "false",
                "0",
                "no",
                "off",
            ],
        ];
        $result = $this->mapper->map("coercion", $source, ActionType::Create);

        $this->assertSame(
            [true, true, true, true, false, false, false, false],
            $result->boolArray,
        );
    }

    public function testMapBoolArrayWithInvalidValueThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayWithTypeCoercion::class,
                    type: "coercion",
                    attributes: [
                        new Attribute(
                            "boolArray",
                            "bool[]",
                            setter: "boolArray",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "coercion")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(TypeMismatchException::class);

        $source = (object) ["boolArray" => [true, "invalid", false]];
        $this->mapper->map("coercion", $source, ActionType::Create);
    }

    // ===== DATETIME ARRAYS =====
    //
    // ===== МАССИВЫ DATETIME =====

    public function testMapDateTimeArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("dates", "datetime[]", setter: "dates"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "dates" => ["2024-01-01", "2024-06-15", "2024-12-31"],
        ];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);
        $dates = $result->dates;

        $this->assertCount(3, $dates);
        $this->assertContainsOnlyInstancesOf(
            DateTimeInterface::class,
            $dates,
        );
        $this->assertSame("2024-01-01", $result->dates[0]->format("Y-m-d"));
        $this->assertSame("2024-06-15", $result->dates[1]->format("Y-m-d"));
        $this->assertSame("2024-12-31", $result->dates[2]->format("Y-m-d"));
    }

    public function testMapDateTimeArrayWithObjects(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("dates", "datetime[]", setter: "dates"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $date1 = new DateTimeImmutable("2024-01-01");
        $date2 = new DateTimeImmutable("2024-12-31");

        $source = (object) ["dates" => [$date1, $date2]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertCount(2, $result->dates);
        $this->assertSame($date1, $result->dates[0]);
        $this->assertSame($date2, $result->dates[1]);
    }

    // ===== UUID ARRAYS =====
    //
    // ===== МАССИВЫ UUID =====

    public function testMapUuidArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("uuids", "uuid[]", setter: "uuids"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $uuids = [
            "550e8400-e29b-41d4-a716-446655440000",
            "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
            "12345678-1234-1234-1234-123456789abc",
        ];

        $source = (object) ["uuids" => $uuids];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame($uuids, $result->uuids);
    }

    public function testMapUuidArrayWithInvalidUuidThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("uuids", "uuid[]", setter: "uuids"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(TypeMismatchException::class);

        $source = (object) [
            "uuids" => ["550e8400-e29b-41d4-a716-446655440000", "not-a-uuid"],
        ];
        $this->mapper->map("arrays", $source, ActionType::Create);
    }

    // ===== NULLABLE ARRAYS =====
    //
    // ===== NULLABLE-МАССИВЫ =====

    public function testMapNullableArrayWithNull(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestNullableArray::class,
                    type: "nullable",
                    attributes: [
                        new Attribute("tags", "string[]|null", setter: "tags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "nullable")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["tags" => null];
        $result = $this->mapper->map("nullable", $source, ActionType::Create);

        $this->assertNull($result->tags);
    }

    public function testMapNullableArrayWithValue(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestNullableArray::class,
                    type: "nullable",
                    attributes: [
                        new Attribute("tags", "string[]|null", setter: "tags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "nullable")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["tags" => ["php", "testing"]];
        $result = $this->mapper->map("nullable", $source, ActionType::Create);

        $this->assertSame(["php", "testing"], $result->tags);
    }

    public function testMapNullableIntArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestNullableArray::class,
                    type: "nullable",
                    attributes: [
                        new Attribute(
                            "numbers",
                            "int[]|null",
                            setter: "numbers",
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "nullable")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["numbers" => null];
        $result = $this->mapper->map("nullable", $source, ActionType::Create);

        $this->assertNull($result->numbers);
    }

    // ===== CONSTRUCTOR WITH ARRAYS =====
    //
    // ===== КОНСТРУКТОР С МАССИВАМИ =====

    public function testMapConstructorWithArrays(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestConstructorWithArray::class,
                    type: "constructor",
                    attributes: [
                        new Attribute("name", "string", constructorParam: true),
                        new Attribute(
                            "tags",
                            "string[]",
                            constructorParam: true,
                        ),
                        new Attribute(
                            "scores",
                            "int[]",
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "constructor")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Test Product",
            "tags" => ["electronics", "gadget"],
            "scores" => [10, 20, 30],
        ];

        $result = $this->mapper->map(
            "constructor",
            $source,
            ActionType::Create,
        );

        $this->assertInstanceOf(TestConstructorWithArray::class, $result);
        $this->assertSame("Test Product", $result->name);
        $this->assertSame(["electronics", "gadget"], $result->tags);
        $this->assertSame([10, 20, 30], $result->scores);
    }

    public function testMapConstructorWithEmptyArrays(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestConstructorWithArray::class,
                    type: "constructor",
                    attributes: [
                        new Attribute("name", "string", constructorParam: true),
                        new Attribute(
                            "tags",
                            "string[]",
                            constructorParam: true,
                        ),
                        new Attribute(
                            "scores",
                            "int[]",
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "constructor")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Test",
            "tags" => [],
            "scores" => [],
        ];

        $result = $this->mapper->map(
            "constructor",
            $source,
            ActionType::Create,
        );

        $this->assertSame([], $result->tags);
        $this->assertSame([], $result->scores);
    }

    public function testMapConstructorWithArrayTypeCoercion(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestConstructorWithArray::class,
                    type: "constructor",
                    attributes: [
                        new Attribute("name", "string", constructorParam: true),
                        new Attribute(
                            "tags",
                            "string[]",
                            constructorParam: true,
                        ),
                        new Attribute(
                            "scores",
                            "int[]",
                            constructorParam: true,
                        ),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "constructor")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "name" => "Test",
            // Will be converted to strings
            //
            // Будут приведены к строкам
            "tags" => [1, 2, true, 3.5],
            // Will be converted to ints
            //
            // Будут приведены к int
            "scores" => ["10", "20", "30"],
        ];

        $result = $this->mapper->map(
            "constructor",
            $source,
            ActionType::Create,
        );

        $this->assertSame(["1", "2", "1", "3.5"], $result->tags);
        $this->assertSame([10, 20, 30], $result->scores);
    }

    // ===== MULTIPLE ARRAYS =====
    //
    // ===== НЕСКОЛЬКО МАССИВОВ =====

    public function testMapMultipleArraysSimultaneously(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("tags", "string[]", setter: "tags"),
                        new Attribute("scores", "int[]", setter: "scores"),
                        new Attribute("prices", "float[]", setter: "prices"),
                        new Attribute("flags", "bool[]", setter: "flags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) [
            "tags" => ["php", "testing"],
            "scores" => [100, 200],
            "prices" => [9.99, 19.99],
            "flags" => [true, false],
        ];

        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame(["php", "testing"], $result->tags);
        $this->assertSame([100, 200], $result->scores);
        $this->assertSame([9.99, 19.99], $result->prices);
        $this->assertSame([true, false], $result->flags);
    }

    // ===== ERROR CASES =====
    //
    // ===== ОШИБОЧНЫЕ СЛУЧАИ =====

    public function testMapNonArrayToArrayTypeThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("tags", "string[]", setter: "tags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(TypeMismatchException::class);

        $source = (object) ["tags" => "not-an-array"];
        $this->mapper->map("arrays", $source, ActionType::Create);
    }

    public function testMapArrayWithObjectThrowsException(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("tags", "string[]", setter: "tags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $this->expectException(TypeMismatchException::class);

        $source = (object) ["tags" => ["string", (object) ["key" => "value"]]];
        $this->mapper->map("arrays", $source, ActionType::Create);
    }

    // ===== EDGE CASES =====
    //
    // ===== ГРАНИЧНЫЕ СЛУЧАИ =====

    public function testMapArrayWithNumericKeys(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("tags", "string[]", setter: "tags"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        // PHP arrays can have numeric keys
        //
        // Массивы PHP могут иметь числовые ключи
        $source = (object) [
            "tags" => [0 => "first", 1 => "second", 2 => "third"],
        ];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertSame(["first", "second", "third"], $result->tags);
    }

    public function testMapArrayPreservesOrder(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("scores", "int[]", setter: "scores"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $source = (object) ["scores" => [5, 3, 8, 1, 9, 2]];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        // Order should be preserved
        //
        // Порядок должен сохраняться
        $this->assertSame([5, 3, 8, 1, 9, 2], $result->scores);
    }

    public function testMapLargeArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArrayTypes::class,
                    type: "arrays",
                    attributes: [
                        new Attribute("scores", "int[]", setter: "scores"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "arrays")],
        );

        $this->mapper->setConfig($config);

        $largeArray = range(1, 1000);
        $source = (object) ["scores" => $largeArray];
        $result = $this->mapper->map("arrays", $source, ActionType::Create);

        $this->assertCount(1000, $result->scores);
        $this->assertSame($largeArray, $result->scores);
    }
}
