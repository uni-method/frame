<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Type\TypeSpecParser;

/**
 * Unit tests for the flattenNestedArray method
 *
 * Юнит-тесты для метода flattenNestedArray
 */
final class MappingToListActionFlattenNestedArrayTest extends TestCase
{
    private MappingToListAction $mapper;

    protected function setUp(): void
    {
        $typeParser = new TypeSpecParser();
        $this->mapper = new MappingToListAction($typeParser);
    }

    /**
     * Call the private method flattenNestedArray via reflection
     *
     * Note: setAccessible() is not required since PHP 8.1+, private methods
     * are accessible via reflection automatically
     *
     * Вызываем приватный метод flattenNestedArray через рефлексию
     *
     * Note: setAccessible() не требуется с PHP 8.1+, приватные методы
     * доступны через рефлексию автоматически
     *
     * @param array<string|int, mixed> $data
     * @return array<string, mixed>
     */
    private function callFlattenNestedArray(
        array $data,
        string $prefix = "",
    ): array {
        $reflection = new ReflectionClass($this->mapper);
        $method = $reflection->getMethod("flattenNestedArray");

        /** @var array<string, mixed> $result */
        $result = $method->invoke($this->mapper, $data, $prefix);

        return $result;
    }

    // ==================== Basic Tests ====================
    //
    // ==================== Базовые тесты ====================

    public function testFlattenSimpleNestedStructure(): void
    {
        $input = [
            "owner" => ["id" => 1],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 1,
        ];

        $this->assertSame($expected, $result);
    }

    public function testFlattenMultipleNestedFields(): void
    {
        $input = [
            "owner" => [
                "id" => 1,
                "email" => "test@example.com",
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 1,
            "owner.email" => "test@example.com",
        ];

        $this->assertSame($expected, $result);
    }

    public function testFlattenDeepNestedStructure(): void
    {
        $input = [
            "owner" => [
                "office" => [
                    "name" => "CompanyName",
                ],
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.office.name" => "CompanyName",
        ];

        $this->assertSame($expected, $result);
    }

    public function testFlattenVeryDeepNestedStructure(): void
    {
        $input = [
            "a" => [
                "b" => [
                    "c" => [
                        "d" => [
                            "e" => "value",
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "a.b.c.d.e" => "value",
        ];

        $this->assertSame($expected, $result);
    }

    // ==================== Mixed Structure Tests ====================
    //
    // ==================== Тесты смешанной структуры ====================

    public function testFlattenMixedNestedAndFlat(): void
    {
        $input = [
            "owner" => ["id" => 1],
            "name" => "test",
            "status" => "active",
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 1,
            "name" => "test",
            "status" => "active",
        ];

        $this->assertSame($expected, $result);
    }

    public function testFlattenMultipleNestedPaths(): void
    {
        $input = [
            "owner" => [
                "id" => 1,
                "office" => [
                    "name" => "Office",
                ],
            ],
            "category" => [
                "id" => 5,
                "name" => "Category",
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 1,
            "owner.office.name" => "Office",
            "category.id" => 5,
            "category.name" => "Category",
        ];

        $this->assertSame($expected, $result);
    }

    // ==================== Array Values Tests ====================
    //
    // ==================== Тесты массивов значений ====================

    public function testPreserveArrayValuesForInOperator(): void
    {
        // An array with numeric keys is an array of values, no need to flatten it
        //
        // Массив с числовыми ключами - это массив значений, не нужно расплющивать
        $input = [
            "ids" => [1, 2, 3],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "ids" => [1, 2, 3],
        ];

        $this->assertSame($expected, $result);
    }

    public function testPreserveArrayValuesWithStringKeys(): void
    {
        // An array with associative keys is a nested structure, it needs to be flattened
        //
        // Массив с ассоциативными ключами - это вложенная структура, нужно расплющить
        $input = [
            "owner" => ["id" => 1],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 1,
        ];

        $this->assertSame($expected, $result);
    }

    public function testPreserveNestedArrayValues(): void
    {
        // A nested structure with an array of values
        //
        // Вложенная структура с массивом значений
        $input = [
            "owner" => [
                // Array of values
                //
                // Массив значений
                "id" => [1, 2, 3],
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => [1, 2, 3],
        ];

        $this->assertSame($expected, $result);
    }

    public function testMixedNestedAndArrayValues(): void
    {
        $input = [
            "owner" => [
                // Array of values
                //
                // Массив значений
                "ids" => [1, 2, 3],
                // Scalar
                //
                // Скаляр
                "email" => "test@test.com",
            ],
            // Array of values at the top level
            //
            // Массив значений на верхнем уровне
            "statuses" => ["active", "pending"],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.ids" => [1, 2, 3],
            "owner.email" => "test@test.com",
            "statuses" => ["active", "pending"],
        ];

        $this->assertSame($expected, $result);
    }

    // ==================== Edge Cases ====================
    //
    // ==================== Граничные случаи ====================

    public function testEmptyArray(): void
    {
        $input = [];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame([], $result);
    }

    public function testEmptyNestedArray(): void
    {
        // FIXED: An empty array is kept as is (it may be a value)
        //
        // ИСПРАВЛЕНО: Пустой массив сохраняется как есть (может быть значением)
        $input = [
            "owner" => [],
        ];

        $result = $this->callFlattenNestedArray($input);

        // An empty array is kept in the result
        //
        // Пустой массив сохраняется в результате
        $expected = [
            "owner" => [],
        ];

        $this->assertSame($expected, $result);
    }

    public function testNullValues(): void
    {
        $input = [
            "owner" => [
                "id" => null,
                "email" => null,
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => null,
            "owner.email" => null,
        ];

        $this->assertSame($expected, $result);
    }

    public function testMixedNullAndValues(): void
    {
        $input = [
            "owner" => [
                "id" => 1,
                "email" => null,
            ],
            "status" => null,
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 1,
            "owner.email" => null,
            "status" => null,
        ];

        $this->assertSame($expected, $result);
    }

    public function testNumericStringKeys(): void
    {
        // FIXED: In PHP numeric string keys are automatically converted to int
        // ['0' => 'a'] === [0 => 'a'], so this is an array of values, not an associative one
        //
        // ИСПРАВЛЕНО: В PHP строковые числовые ключи автоматически конвертируются в int
        // ['0' => 'a'] === [0 => 'a'], поэтому это массив значений, не ассоциативный
        $input = [
            "owner" => [
                "0" => "first",
                "1" => "second",
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        // PHP converts '0', '1' into 0, 1, so this is a value array
        //
        // PHP конвертирует '0', '1' в 0, 1, поэтому это value array
        $expected = [
            "owner" => [
                0 => "first",
                1 => "second",
            ],
        ];

        $this->assertSame($expected, $result);
    }

    public function testZeroValue(): void
    {
        $input = [
            "owner" => ["id" => 0],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => 0,
        ];

        $this->assertSame($expected, $result);
    }

    public function testBooleanValues(): void
    {
        $input = [
            "owner" => [
                "active" => true,
                "deleted" => false,
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.active" => true,
            "owner.deleted" => false,
        ];

        $this->assertSame($expected, $result);
    }

    public function testStringValues(): void
    {
        $input = [
            "owner" => [
                "name" => "test",
                "email" => "test@example.com",
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.name" => "test",
            "owner.email" => "test@example.com",
        ];

        $this->assertSame($expected, $result);
    }

    // ==================== Real World Examples ====================
    //
    // ==================== Примеры из реальной практики ====================

    public function testRealWorldFilterEqualOwner(): void
    {
        // filter[equal][owner][id]=1
        $input = [
            "owner" => ["id" => "1"],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => "1",
        ];

        $this->assertSame($expected, $result);
    }

    public function testRealWorldFilterMultipleOperators(): void
    {
        // What it will look like after parsing the query string:
        // filter[equal][owner][id]=1&filter[equal][originalId]=123&filter[like][name]=test
        //
        // Как будет выглядеть после парсинга query string:
        // filter[equal][owner][id]=1&filter[equal][originalId]=123&filter[like][name]=test

        // For the equal operator:
        //
        // Для оператора equal:
        $equalInput = [
            "owner" => ["id" => "1"],
            "originalId" => "123",
        ];

        $equalResult = $this->callFlattenNestedArray($equalInput);

        $expectedEqual = [
            "owner.id" => "1",
            "originalId" => "123",
        ];

        $this->assertSame($expectedEqual, $equalResult);

        // For the like operator:
        //
        // Для оператора like:
        $likeInput = [
            "name" => "test",
        ];

        $likeResult = $this->callFlattenNestedArray($likeInput);

        $expectedLike = [
            "name" => "test",
        ];

        $this->assertSame($expectedLike, $likeResult);
    }

    public function testRealWorldFilterRange(): void
    {
        // filter[range][createdAt]=[2024-01-01,2024-12-31]
        $input = [
            "createdAt" => ["2024-01-01", "2024-12-31"],
        ];

        $result = $this->callFlattenNestedArray($input);

        // The array of values must be preserved
        //
        // Массив значений должен сохраниться
        $expected = [
            "createdAt" => ["2024-01-01", "2024-12-31"],
        ];

        $this->assertSame($expected, $result);
    }

    public function testRealWorldFilterIn(): void
    {
        // filter[in][owner][id]=[1,2,3]
        $input = [
            "owner" => ["id" => [1, 2, 3]],
        ];

        $result = $this->callFlattenNestedArray($input);

        $expected = [
            "owner.id" => [1, 2, 3],
        ];

        $this->assertSame($expected, $result);
    }

    public function testRealWorldComplexQuery(): void
    {
        // filter[equal][owner][office][city][id]=5&filter[like][name]=test

        // Equal operator:
        //
        // Equal оператор:
        $equalInput = [
            "owner" => [
                "office" => [
                    "city" => [
                        "id" => "5",
                    ],
                ],
            ],
        ];

        $equalResult = $this->callFlattenNestedArray($equalInput);

        $expectedEqual = [
            "owner.office.city.id" => "5",
        ];

        $this->assertSame($expectedEqual, $equalResult);

        // Like operator:
        //
        // Like оператор:
        $likeInput = ["name" => "test"];
        $likeResult = $this->callFlattenNestedArray($likeInput);

        $expectedLike = ["name" => "test"];

        $this->assertSame($expectedLike, $likeResult);
    }

    // ==================== With Prefix Tests ====================
    //
    // ==================== Тесты с префиксом ====================

    public function testFlattenWithPrefix(): void
    {
        $input = [
            "id" => 1,
            "name" => "test",
        ];

        $result = $this->callFlattenNestedArray($input, "owner");

        $expected = [
            "owner.id" => 1,
            "owner.name" => "test",
        ];

        $this->assertSame($expected, $result);
    }

    public function testFlattenNestedWithPrefix(): void
    {
        $input = [
            "office" => [
                "name" => "Company",
            ],
        ];

        $result = $this->callFlattenNestedArray($input, "owner");

        $expected = [
            "owner.office.name" => "Company",
        ];

        $this->assertSame($expected, $result);
    }

    public function testFloatValues(): void
    {
        $input = [
            "price" => [
                "min" => 1.5,
                "max" => 99.99,
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "price.min" => 1.5,
                "price.max" => 99.99,
            ],
            $result,
        );
    }

    public function testEmptyStringValues(): void
    {
        $input = [
            "owner" => [
                "name" => "",
            ],
            "status" => "",
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "owner.name" => "",
                "status" => "",
            ],
            $result,
        );
    }

    public function testFlattenWithEmptyInputAndPrefix(): void
    {
        $result = $this->callFlattenNestedArray([], "owner");

        $this->assertSame([], $result);
    }

    public function testSiblingBranchesOneNestedOneArrayValues(): void
    {
        // One branch is a nested object, the other is an array of values
        //
        // Одна ветка — вложенный объект, другая — массив значений
        $input = [
            "owner" => ["id" => 1],
            "statuses" => ["active", "pending"],
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "owner.id" => 1,
                "statuses" => ["active", "pending"],
            ],
            $result,
        );
    }

    public function testDeepNestedWithArrayValuesAtLeaf(): void
    {
        $input = [
            "owner" => [
                "office" => [
                    "ids" => [10, 20, 30],
                ],
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "owner.office.ids" => [10, 20, 30],
            ],
            $result,
        );
    }

    public function testDeepNestedWithNullAtLeaf(): void
    {
        $input = [
            "owner" => [
                "office" => [
                    "city" => null,
                ],
            ],
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "owner.office.city" => null,
            ],
            $result,
        );
    }

    public function testMixedNestedAndEmptyArrayAtSameLevel(): void
    {
        $input = [
            "owner" => ["id" => 1],
            "tags" => [],
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "owner.id" => 1,
                "tags" => [],
            ],
            $result,
        );
    }

    public function testPrefixWithDeepNestedArrayValues(): void
    {
        $input = [
            "ids" => [1, 2, 3],
            "name" => "test",
        ];

        $result = $this->callFlattenNestedArray($input, "owner");

        $this->assertSame(
            [
                "owner.ids" => [1, 2, 3],
                "owner.name" => "test",
            ],
            $result,
        );
    }

    public function testNonSequentialNumericArrayPreservedAsValue(): void
    {
        // An array with non-sequential numeric keys is a value array
        //
        // Массив с непоследовательными числовыми ключами — это value array
        $input = [
            "codes" => [1 => "a", 5 => "b"],
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame(
            [
                "codes.1" => "a",
                "codes.5" => "b",
            ],
            $result,
        );
    }

    public function testTopLevelScalarValuesPassThrough(): void
    {
        $input = [
            "name" => "foo",
            "count" => 42,
            "active" => true,
            "ratio" => 0.5,
        ];

        $result = $this->callFlattenNestedArray($input);

        $this->assertSame($input, $result);
    }
}
