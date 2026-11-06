<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniMethod\Frame\Config\ListAction\FilterOperator;
use UniMethod\Frame\Mapping\ListQuery\Exception\InvalidListQueryException;
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Type\{TypeSpec, TypeSpecParser};

final class MappingToListActionConvertValueTest extends TestCase
{
    private MappingToListAction $mapper;

    private TypeSpecParser $typeParser;

    protected function setUp(): void
    {
        $this->typeParser = new TypeSpecParser();
        $this->mapper = new MappingToListAction($this->typeParser);
    }

    private function callConvertValue(
        mixed $value,
        TypeSpec $typeSpec,
        FilterOperator $operator,
    ): mixed {
        $reflection = new ReflectionClass($this->mapper);
        $method = $reflection->getMethod("convertValue");

        return $method->invoke($this->mapper, $value, $typeSpec, $operator);
    }

    // ==================== Array operator requires array ====================
    //
    // ==================== Оператор-массив требует массив ====================

    public function testInOperatorWithScalarValueThrows(): void
    {
        $typeSpec = $this->typeParser->parse("int");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage(
            sprintf(
                "Operator %s requires array value",
                FilterOperator::In->value,
            ),
        );

        $this->callConvertValue("1", $typeSpec, FilterOperator::In);
    }

    public function testRangeOperatorWithScalarValueThrows(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage(
            sprintf(
                "Operator %s requires array value",
                FilterOperator::Range->value,
            ),
        );

        $this->callConvertValue("value", $typeSpec, FilterOperator::Range);
    }

    public function testInOperatorWithNullValueThrows(): void
    {
        $typeSpec = $this->typeParser->parse("int");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage(
            sprintf(
                "Operator %s requires array value",
                FilterOperator::In->value,
            ),
        );

        $this->callConvertValue(null, $typeSpec, FilterOperator::In);
    }

    public function testInOperatorWithArrayValueConvertsEachElement(): void
    {
        $typeSpec = $this->typeParser->parse("int");

        $result = $this->callConvertValue(
            ["1", "2", "3"],
            $typeSpec,
            FilterOperator::In,
        );

        $this->assertSame([1, 2, 3], $result);
    }

    public function testRangeOperatorWithArrayValueConvertsEachElement(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $result = $this->callConvertValue(
            ["2024-01-01", "2024-12-31"],
            $typeSpec,
            FilterOperator::Range,
        );

        $this->assertSame(["2024-01-01", "2024-12-31"], $result);
    }

    // ==================== isList field requires array ====================
    //
    // ==================== Поле isList требует массив ====================

    public function testListFieldWithScalarValueThrows(): void
    {
        $typeSpec = $this->typeParser->parse("string[]");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("List field requires array value");

        $this->callConvertValue("single", $typeSpec, FilterOperator::Equal);
    }

    public function testListFieldWithNullValueThrows(): void
    {
        $typeSpec = $this->typeParser->parse("string[]");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("List field requires array value");

        $this->callConvertValue(null, $typeSpec, FilterOperator::Equal);
    }

    public function testListFieldWithArrayValueConvertsEachElement(): void
    {
        $typeSpec = $this->typeParser->parse("int[]");

        $result = $this->callConvertValue(
            ["10", "20", "30"],
            $typeSpec,
            FilterOperator::Equal,
        );

        $this->assertSame([10, 20, 30], $result);
    }

    public function testListFieldWithEmptyArrayReturnsEmptyArray(): void
    {
        $typeSpec = $this->typeParser->parse("string[]");

        $result = $this->callConvertValue([], $typeSpec, FilterOperator::Equal);

        $this->assertSame([], $result);
    }
}
