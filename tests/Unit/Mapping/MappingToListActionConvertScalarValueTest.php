<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use UniMethod\Frame\Mapping\ListQuery\Exception\InvalidListQueryException;
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Type\{TypeSpec, TypeSpecParser};

final class MappingToListActionConvertScalarValueTest extends TestCase
{
    private MappingToListAction $mapper;

    private TypeSpecParser $typeParser;

    protected function setUp(): void
    {
        $this->typeParser = new TypeSpecParser();
        $this->mapper = new MappingToListAction($this->typeParser);
    }

    private function callConvertScalarValue(
        mixed $value,
        TypeSpec $typeSpec,
    ): mixed {
        $reflection = new ReflectionClass($this->mapper);
        $method = $reflection->getMethod("convertScalarValue");

        return $method->invoke($this->mapper, $value, $typeSpec);
    }

    // ==================== InvalidListQueryException ====================

    public function testNullValueOnNonNullableFieldThrows(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Non-nullable field cannot be null");

        $this->callConvertScalarValue(null, $typeSpec);
    }

    // ==================== ValueKind::Int ====================

    public function testIntFromString(): void
    {
        $typeSpec = $this->typeParser->parse("int");

        $this->assertSame(42, $this->callConvertScalarValue("42", $typeSpec));
    }

    /**
     * A non-numeric value for int/float — 400, not a silent 0.
     *
     * Нечисловое значение для int/float — 400, а не молчаливый 0.
     */
    #[DataProvider("provideNonNumericValues")]
    public function testNonNumericValueIsRejected(string $type, string $value): void
    {
        $typeSpec = $this->typeParser->parse($type);

        $this->expectException(InvalidListQueryException::class);

        $this->callConvertScalarValue($value, $typeSpec);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNonNumericValues(): iterable
    {
        yield "int: буквы" => ["int", "abc"];
        yield "int: дробное" => ["int", "1.5"];
        yield "float: буквы" => ["float", "abc"];
    }

    // ==================== ValueKind::Float ====================

    public function testFloatFromString(): void
    {
        $typeSpec = $this->typeParser->parse("float");

        $result = $this->callConvertScalarValue("3.14", $typeSpec);

        $this->assertSame(3.14, $result);
    }

    public function testFloatFromInt(): void
    {
        $typeSpec = $this->typeParser->parse("float");

        $result = $this->callConvertScalarValue(42, $typeSpec);

        $this->assertSame(42.0, $result);
    }

    public function testFloatFromZeroString(): void
    {
        $typeSpec = $this->typeParser->parse("float");

        $result = $this->callConvertScalarValue("0", $typeSpec);

        $this->assertSame(0.0, $result);
    }

    // ==================== ValueKind::Bool ====================

    public function testBoolFromStringTrue(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $result = $this->callConvertScalarValue("true", $typeSpec);

        $this->assertTrue($result);
    }

    public function testBoolFromStringFalse(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $result = $this->callConvertScalarValue("false", $typeSpec);

        $this->assertFalse($result);
    }

    public function testBoolFromStringOne(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $result = $this->callConvertScalarValue("1", $typeSpec);

        $this->assertTrue($result);
    }

    public function testBoolFromStringZero(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $result = $this->callConvertScalarValue("0", $typeSpec);

        $this->assertFalse($result);
    }

    public function testBoolFromInvalidStringIsRejected(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $this->expectException(InvalidListQueryException::class);

        $this->callConvertScalarValue("abc", $typeSpec);
    }

    public function testBoolFromNativeBoolTrue(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $result = $this->callConvertScalarValue(true, $typeSpec);

        $this->assertTrue($result);
    }

    public function testBoolFromNativeBoolFalse(): void
    {
        $typeSpec = $this->typeParser->parse("bool");

        $result = $this->callConvertScalarValue(false, $typeSpec);

        $this->assertFalse($result);
    }

    // ==================== ValueKind::DateTime ====================

    public function testDateTimeFromString(): void
    {
        $typeSpec = $this->typeParser->parse("datetime");

        $result = $this->callConvertScalarValue(
            "2024-01-15T12:00:00",
            $typeSpec,
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame("2024-01-15", $result->format("Y-m-d"));
    }

    public function testDateTimeFromDateOnlyString(): void
    {
        $typeSpec = $this->typeParser->parse("datetime");

        $result = $this->callConvertScalarValue("2024-06-01", $typeSpec);

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame("2024-06-01", $result->format("Y-m-d"));
    }

    /**
     * Only absolute ISO 8601 dates are accepted; garbage and relative
     * formats — 400.
     *
     * Принимаются только абсолютные даты ISO 8601; мусор и относительные
     * форматы — 400.
     */
    #[DataProvider("provideInvalidDateValues")]
    public function testDateTimeFromInvalidStringIsRejected(string $value): void
    {
        $typeSpec = $this->typeParser->parse("datetime");

        $this->expectException(InvalidListQueryException::class);

        $this->callConvertScalarValue($value, $typeSpec);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidDateValues(): iterable
    {
        yield "мусор" => ["not-a-date"];
        yield "now" => ["now"];
        yield "+1 day" => ["+1 day"];
        yield "tomorrow" => ["tomorrow"];
        yield "last monday" => ["last monday"];
        yield "@timestamp" => ["@0"];
    }

    public function testDateTimePassthroughWhenAlreadyDateTimeImmutable(): void
    {
        $typeSpec = $this->typeParser->parse("datetime");
        $dt = new DateTimeImmutable("2024-01-15");

        $result = $this->callConvertScalarValue($dt, $typeSpec);

        $this->assertSame($dt, $result);
    }

    // ==================== ValueKind::Uuid ====================

    public function testUuidFromString(): void
    {
        $typeSpec = $this->typeParser->parse("uuid");
        $uuid = "550e8400-e29b-41d4-a716-446655440000";

        $result = $this->callConvertScalarValue($uuid, $typeSpec);

        $this->assertSame($uuid, $result);
    }

    public function testUuidFromIntIsRejected(): void
    {
        $typeSpec = $this->typeParser->parse("uuid");

        $this->expectException(InvalidListQueryException::class);

        $this->callConvertScalarValue(123, $typeSpec);
    }

    // ==================== Empty string convention ====================
    //
    // ==================== Соглашение о пустой строке ====================

    public function testEmptyStringOnNullableFieldReturnsNull(): void
    {
        $typeSpec = $this->typeParser->parse("string|null");

        $result = $this->callConvertScalarValue("", $typeSpec);

        $this->assertNull($result);
    }

    public function testEmptyStringOnNonNullableFieldThrows(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Non-nullable field cannot be null");

        $this->callConvertScalarValue("", $typeSpec);
    }

    public function testTwoQuotesProducesEmptyString(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $result = $this->callConvertScalarValue("''", $typeSpec);

        $this->assertSame("", $result);
    }

    public function testFourQuotesProducesTwoQuotes(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $result = $this->callConvertScalarValue("''''", $typeSpec);

        $this->assertSame("''", $result);
    }

    public function testSixQuotesProducesFourQuotes(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $result = $this->callConvertScalarValue("''''''", $typeSpec);

        $this->assertSame("''''", $result);
    }

    public function testOddNumberOfQuotesIsNotStripped(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $result = $this->callConvertScalarValue("'''", $typeSpec);

        $this->assertSame("'''", $result);
    }

    public function testStringWithMixedContentIsNotStripped(): void
    {
        $typeSpec = $this->typeParser->parse("string");

        $result = $this->callConvertScalarValue("'hello'", $typeSpec);

        $this->assertSame("'hello'", $result);
    }
}
