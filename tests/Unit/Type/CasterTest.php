<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Type;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Type\{Caster, TypeCastException, TypeSpec, ValueKind};

final class CasterTest extends TestCase
{
    private Caster $caster;

    protected function setUp(): void
    {
        $this->caster = new Caster();
    }

    // ===== STRING TESTS =====
    //
    // ===== ТЕСТЫ STRING =====

    public function testCastStringToString(): void
    {
        $spec = new TypeSpec(ValueKind::String);
        $result = $this->caster->cast("hello", $spec);

        $this->assertSame("hello", $result);
    }

    public function testCastIntToString(): void
    {
        $spec = new TypeSpec(ValueKind::String);
        $result = $this->caster->cast(42, $spec);

        $this->assertSame("42", $result);
    }

    public function testCastFloatToString(): void
    {
        $spec = new TypeSpec(ValueKind::String);
        $result = $this->caster->cast(3.14, $spec);

        $this->assertSame("3.14", $result);
    }

    public function testCastBoolToString(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame("1", $this->caster->cast(true, $spec));
        $this->assertSame("", $this->caster->cast(false, $spec));
    }

    public function testCastArrayToStringFails(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $this->expectException(TypeCastException::class);
        $this->expectExceptionMessage(
            "Cannot cast value of type array to string",
        );

        $this->caster->cast(["test"], $spec);
    }

    public function testCastObjectToStringFails(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $this->expectException(TypeCastException::class);

        $this->caster->cast(new \stdClass(), $spec);
    }

    // ===== INT TESTS =====
    //
    // ===== ТЕСТЫ INT =====

    public function testCastIntToInt(): void
    {
        $spec = new TypeSpec(ValueKind::Int);
        $result = $this->caster->cast(42, $spec);

        $this->assertSame(42, $result);
    }

    public function testCastStringToInt(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->assertSame(123, $this->caster->cast("123", $spec));
        $this->assertSame(-456, $this->caster->cast("-456", $spec));
        $this->assertSame(0, $this->caster->cast("0", $spec));
    }

    public function testCastFloatToInt(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->assertSame(5, $this->caster->cast(5.0, $spec));
        $this->assertSame(-10, $this->caster->cast(-10.0, $spec));
    }

    public function testCastFloatWithDecimalsToIntFails(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->expectException(TypeCastException::class);
        $this->expectExceptionMessage("Cannot cast value of type float to int");

        $this->caster->cast(3.14, $spec);
    }

    public function testCastInvalidStringToIntFails(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->expectException(TypeCastException::class);

        $this->caster->cast("hello", $spec);
    }

    public function testCastFloatStringToIntFails(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->expectException(TypeCastException::class);

        $this->caster->cast("3.14", $spec);
    }

    // ===== FLOAT TESTS =====
    //
    // ===== ТЕСТЫ FLOAT =====

    public function testCastFloatToFloat(): void
    {
        $spec = new TypeSpec(ValueKind::Float);
        $result = $this->caster->cast(3.14, $spec);

        $this->assertSame(3.14, $result);
    }

    public function testCastIntToFloat(): void
    {
        $spec = new TypeSpec(ValueKind::Float);
        $result = $this->caster->cast(42, $spec);

        $this->assertSame(42.0, $result);
    }

    public function testCastStringToFloat(): void
    {
        $spec = new TypeSpec(ValueKind::Float);

        $this->assertSame(3.14, $this->caster->cast("3.14", $spec));
        $this->assertSame(123.0, $this->caster->cast("123", $spec));
        $this->assertSame(-45.67, $this->caster->cast("-45.67", $spec));
    }

    public function testCastInvalidStringToFloatFails(): void
    {
        $spec = new TypeSpec(ValueKind::Float);

        $this->expectException(TypeCastException::class);
        $this->expectExceptionMessage(
            "Cannot cast value of type string to float",
        );

        $this->caster->cast("not a number", $spec);
    }

    public function testCastArrayToFloatFails(): void
    {
        $spec = new TypeSpec(ValueKind::Float);

        $this->expectException(TypeCastException::class);

        $this->caster->cast([1, 2, 3], $spec);
    }

    // ===== BOOL TESTS =====
    //
    // ===== ТЕСТЫ BOOL =====

    public function testCastBoolToBool(): void
    {
        $spec = new TypeSpec(ValueKind::Bool);

        $this->assertTrue($this->caster->cast(true, $spec));
        $this->assertFalse($this->caster->cast(false, $spec));
    }

    public function testCastStringToBool(): void
    {
        $spec = new TypeSpec(ValueKind::Bool);

        $this->assertTrue($this->caster->cast("true", $spec));
        $this->assertTrue($this->caster->cast("1", $spec));
        $this->assertTrue($this->caster->cast("yes", $spec));
        $this->assertTrue($this->caster->cast("on", $spec));

        $this->assertFalse($this->caster->cast("false", $spec));
        $this->assertFalse($this->caster->cast("0", $spec));
        $this->assertFalse($this->caster->cast("no", $spec));
        $this->assertFalse($this->caster->cast("off", $spec));
    }

    public function testCastInvalidStringToBoolFails(): void
    {
        $spec = new TypeSpec(ValueKind::Bool);

        $this->expectException(TypeCastException::class);
        $this->expectExceptionMessage(
            "Cannot cast value of type string to bool",
        );

        $this->caster->cast("invalid", $spec);
    }

    public function testCastFalseReturnsFalseNotFailCast(): void
    {
        $spec = new TypeSpec(ValueKind::Bool);

        $result = $this->caster->cast(false, $spec);

        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    public function testCastArrayToBoolFails(): void
    {
        $spec = new TypeSpec(ValueKind::Bool);

        $this->expectException(TypeCastException::class);

        $this->caster->cast([true], $spec);
    }

    // ===== DATETIME TESTS =====
    //
    // ===== ТЕСТЫ DATETIME =====

    public function testCastDateTimeToDateTime(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);
        $date = new DateTimeImmutable("2024-01-15 10:30:00");

        $result = $this->caster->cast($date, $spec);

        $this->assertSame($date, $result);
    }

    public function testCastStringToDateTime(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);

        $result = $this->caster->cast("2024-01-15T10:30:00", $spec);

        $this->assertInstanceOf(DateTimeInterface::class, $result);
        $this->assertSame("2024-01-15", $result->format("Y-m-d"));
    }

    public function testCastIsoStringToDateTime(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);

        $result = $this->caster->cast("2024-01-15T10:30:00+00:00", $spec);

        $this->assertInstanceOf(DateTimeInterface::class, $result);
    }

    #[DataProvider("provideIsoDateTimesWithOffsetOrFraction")]
    public function testCastIsoDateTimeWithZuluOrFraction(
        string $value,
        string $expected,
    ): void {
        $result = $this->caster->cast(
            $value,
            new TypeSpec(ValueKind::DateTime),
        );

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame($expected, $result->format("Y-m-d\\TH:i:s.uP"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideIsoDateTimesWithOffsetOrFraction(): iterable
    {
        yield "Z" => ["2026-01-31T10:15:00Z", "2026-01-31T10:15:00.000000+00:00"];
        yield "toISOString()" => ["2026-01-31T10:15:00.000Z", "2026-01-31T10:15:00.000000+00:00"];
        yield "микросекунды + смещение" => ["2026-01-31T10:15:00.123456+03:00", "2026-01-31T10:15:00.123456+03:00"];
    }

    #[DataProvider("provideNonIsoTimeZones")]
    public function testCastNonIsoTimeZoneToDateTimeFails(string $value): void
    {
        $this->expectException(TypeCastException::class);

        $this->caster->cast($value, new TypeSpec(ValueKind::DateTime));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonIsoTimeZones(): iterable
    {
        yield "аббревиатура" => ["2026-01-31T10:15:00EST"];
        yield "идентификатор" => ["2026-01-31T10:15:00Europe/Moscow"];
        yield "смещение без двоеточия" => ["2026-01-31T10:15:00+0300"];
        yield "больше 6 цифр дробной части" => ["2026-01-31T10:15:00.1234567Z"];
        yield "перевод строки в конце" => ["2026-01-31\n"];
    }

    public function testCastInvalidStringToDateTimeFails(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);

        $this->expectException(TypeCastException::class);

        $this->caster->cast("not a date", $spec);
    }

    public function testCastDateOnlyStringToDateTime(): void
    {
        $result = $this->caster->cast("2026-01-31", new TypeSpec(ValueKind::DateTime));

        $this->assertInstanceOf(DateTimeImmutable::class, $result);
        $this->assertSame("2026-01-31 00:00:00", $result->format("Y-m-d H:i:s"));
    }

    /**
     * Only absolute ISO 8601 dates are accepted: relative formats
     * depend on the moment and the server timezone and are not reproducible.
     *
     * Принимаются только абсолютные даты ISO 8601: относительные форматы
     * зависят от момента и таймзоны сервера и невоспроизводимы.
     */
    #[DataProvider("provideNonIsoDateFormats")]
    public function testCastRelativeOrNonIsoDateToDateTimeFails(string $value): void
    {
        $this->expectException(TypeCastException::class);

        $this->caster->cast($value, new TypeSpec(ValueKind::DateTime));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNonIsoDateFormats(): iterable
    {
        yield "now" => ["now"];
        yield "+1 day" => ["+1 day"];
        yield "tomorrow" => ["tomorrow"];
        yield "last monday" => ["last monday"];
        yield "@timestamp" => ["@0"];
    }

    public function testCastIntToDateTimeFails(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);

        $this->expectException(TypeCastException::class);
        $this->expectExceptionMessage(
            "Cannot cast value of type int to datetime",
        );

        $this->caster->cast(123456, $spec);
    }

    // ===== UUID TESTS =====
    //
    // ===== ТЕСТЫ UUID =====

    public function testCastValidUuidToUuid(): void
    {
        $spec = new TypeSpec(ValueKind::Uuid);
        $uuid = "550e8400-e29b-41d4-a716-446655440000";

        $result = $this->caster->cast($uuid, $spec);

        $this->assertSame($uuid, $result);
    }

    public function testCastUppercaseUuidToUuid(): void
    {
        $spec = new TypeSpec(ValueKind::Uuid);
        $uuid = "550E8400-E29B-41D4-A716-446655440000";

        $result = $this->caster->cast($uuid, $spec);

        $this->assertSame($uuid, $result);
    }

    public function testCastInvalidUuidFails(): void
    {
        $spec = new TypeSpec(ValueKind::Uuid);

        $this->expectException(TypeCastException::class);
        $this->expectExceptionMessage(
            "Cannot cast value of type string to uuid",
        );

        $this->caster->cast("not-a-uuid", $spec);
    }

    public function testCastShortStringToUuidFails(): void
    {
        $spec = new TypeSpec(ValueKind::Uuid);

        $this->expectException(TypeCastException::class);

        $this->caster->cast("123", $spec);
    }

    public function testCastIntToUuidFails(): void
    {
        $spec = new TypeSpec(ValueKind::Uuid);

        $this->expectException(TypeCastException::class);

        $this->caster->cast(123, $spec);
    }

    // ===== NULLABLE TESTS =====
    //
    // ===== ТЕСТЫ NULLABLE =====

    public function testCastNullToNullableType(): void
    {
        $spec = new TypeSpec(ValueKind::String, nullable: true);

        $result = $this->caster->cast(null, $spec);

        $this->assertNull($result);
    }

    public function testCastNullToNonNullableTypeFails(): void
    {
        $spec = new TypeSpec(ValueKind::String, nullable: false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Null given, but type is not nullable");

        $this->caster->cast(null, $spec);
    }

    public function testCastNullToNullableInt(): void
    {
        $spec = new TypeSpec(ValueKind::Int, nullable: true);

        $this->assertNull($this->caster->cast(null, $spec));
    }

    public function testCastNullToNullableList(): void
    {
        $spec = new TypeSpec(ValueKind::String, nullable: true, isList: true);

        $this->assertNull($this->caster->cast(null, $spec));
    }

    // ===== LIST TESTS =====
    //
    // ===== ТЕСТЫ LIST =====

    public function testCastArrayToStringList(): void
    {
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $result = $this->caster->cast(["hello", "world"], $spec);

        $this->assertSame(["hello", "world"], $result);
    }

    public function testCastMixedArrayToStringList(): void
    {
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $result = $this->caster->cast([1, 2.5, "test", true], $spec);

        $this->assertSame(["1", "2.5", "test", "1"], $result);
    }

    public function testCastArrayToIntList(): void
    {
        $spec = new TypeSpec(ValueKind::Int, isList: true);

        $result = $this->caster->cast([1, 2, 3], $spec);

        $this->assertSame([1, 2, 3], $result);
    }

    public function testCastStringArrayToIntList(): void
    {
        $spec = new TypeSpec(ValueKind::Int, isList: true);

        $result = $this->caster->cast(["10", "20", "30"], $spec);

        $this->assertSame([10, 20, 30], $result);
    }

    public function testCastArrayToFloatList(): void
    {
        $spec = new TypeSpec(ValueKind::Float, isList: true);

        $result = $this->caster->cast([1.5, 2.7, 3.9], $spec);

        $this->assertSame([1.5, 2.7, 3.9], $result);
    }

    public function testCastArrayToBoolList(): void
    {
        $spec = new TypeSpec(ValueKind::Bool, isList: true);

        $result = $this->caster->cast([true, false, "yes", "no"], $spec);

        $this->assertSame([true, false, true, false], $result);
    }

    public function testCastArrayToDateTimeList(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime, isList: true);

        $result = $this->caster->cast(["2024-01-01", "2024-12-31"], $spec);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertInstanceOf(DateTimeInterface::class, $result[0]);
        $this->assertInstanceOf(DateTimeInterface::class, $result[1]);
    }

    public function testCastArrayToUuidList(): void
    {
        $spec = new TypeSpec(ValueKind::Uuid, isList: true);
        $uuids = [
            "550e8400-e29b-41d4-a716-446655440000",
            "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
        ];

        $result = $this->caster->cast($uuids, $spec);

        $this->assertSame($uuids, $result);
    }

    public function testCastEmptyArrayToList(): void
    {
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $result = $this->caster->cast([], $spec);

        $this->assertSame([], $result);
    }

    public function testCastNonIterableToListFails(): void
    {
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Expected list/iterable");

        $this->caster->cast("not an array", $spec);
    }

    public function testCastArrayWithInvalidItemToListFails(): void
    {
        $spec = new TypeSpec(ValueKind::Int, isList: true);

        $this->expectException(TypeCastException::class);

        $this->caster->cast([1, 2, "invalid", 4], $spec);
    }

    // ===== EDGE CASES =====
    //
    // ===== ГРАНИЧНЫЕ СЛУЧАИ =====

    public function testCastZeroToString(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame("0", $this->caster->cast(0, $spec));
    }

    public function testCastEmptyStringToString(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame("", $this->caster->cast("", $spec));
    }

    public function testCastNegativeIntToInt(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->assertSame(-42, $this->caster->cast(-42, $spec));
    }

    public function testCastZeroToFloat(): void
    {
        $spec = new TypeSpec(ValueKind::Float);

        $this->assertSame(0.0, $this->caster->cast(0, $spec));
    }

    public function testCastScientificNotationToFloat(): void
    {
        $spec = new TypeSpec(ValueKind::Float);

        $result = $this->caster->cast("1.5e3", $spec);

        $this->assertSame(1500.0, $result);
    }

    // ===== NORMALIZE TESTS =====
    //
    // ===== ТЕСТЫ НОРМАЛИЗАЦИИ =====

    public function testNormalizeDateTimeToIsoString(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);
        $dt = new DateTimeImmutable("2024-01-15 10:30:00+00:00");

        $result = $this->caster->normalize($dt, $spec);

        $this->assertSame("2024-01-15T10:30:00+00:00", $result);
    }

    public function testNormalizeDateTimeWithTimezone(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime);
        $dt = new DateTimeImmutable("2024-01-15 10:30:00+03:00");

        $result = $this->caster->normalize($dt, $spec);

        $this->assertSame($dt->format("c"), $result);
    }

    public function testNormalizeNullDateTime(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime, nullable: true);

        $result = $this->caster->normalize(null, $spec);

        $this->assertNull($result);
    }

    public function testNormalizeDateTimeList(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime, isList: true);

        $dates = [
            new DateTimeImmutable("2024-01-01T00:00:00+00:00"),
            new DateTimeImmutable("2024-12-31T23:59:59+00:00"),
        ];

        $result = $this->caster->normalize($dates, $spec);

        $this->assertSame(
            ["2024-01-01T00:00:00+00:00", "2024-12-31T23:59:59+00:00"],
            $result,
        );
    }

    public function testNormalizeStringKeepsString(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $result = $this->caster->normalize("hello", $spec);

        $this->assertSame("hello", $result);
    }

    public function testNormalizeIntKeepsInt(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $result = $this->caster->normalize(42, $spec);

        $this->assertSame(42, $result);
    }

    public function testNormalizeBoolKeepsBool(): void
    {
        $spec = new TypeSpec(ValueKind::Bool);

        $this->assertTrue($this->caster->normalize(true, $spec));
        $this->assertFalse($this->caster->normalize(false, $spec));
    }

    public function testNormalizeScalarList(): void
    {
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $result = $this->caster->normalize(["a", "b", "c"], $spec);

        $this->assertSame(["a", "b", "c"], $result);
    }

    public function testNormalizeEmptyList(): void
    {
        $spec = new TypeSpec(ValueKind::DateTime, isList: true);

        $result = $this->caster->normalize([], $spec);

        $this->assertSame([], $result);
    }
}
