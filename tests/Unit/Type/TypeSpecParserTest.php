<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Type;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Type\{TypeSpecParser, UnknownTypeException, ValueKind};

final class TypeSpecParserTest extends TestCase
{
    private TypeSpecParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TypeSpecParser();
    }

    public function testParseString(): void
    {
        $spec = $this->parser->parse("string");

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseInt(): void
    {
        $spec = $this->parser->parse("int");

        $this->assertSame(ValueKind::Int, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseInteger(): void
    {
        $spec = $this->parser->parse("integer");

        $this->assertSame(ValueKind::Int, $spec->kind);
    }

    public function testParseFloat(): void
    {
        $spec = $this->parser->parse("float");

        $this->assertSame(ValueKind::Float, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseDouble(): void
    {
        $spec = $this->parser->parse("double");

        $this->assertSame(ValueKind::Float, $spec->kind);
    }

    public function testParseReal(): void
    {
        $spec = $this->parser->parse("real");

        $this->assertSame(ValueKind::Float, $spec->kind);
    }

    public function testParseBool(): void
    {
        $spec = $this->parser->parse("bool");

        $this->assertSame(ValueKind::Bool, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseBoolean(): void
    {
        $spec = $this->parser->parse("boolean");

        $this->assertSame(ValueKind::Bool, $spec->kind);
    }

    public function testParseDateTime(): void
    {
        $spec = $this->parser->parse("datetime");

        $this->assertSame(ValueKind::DateTime, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
        $this->assertSame(DateTimeImmutable::class, $spec->phpClass);
    }

    public function testParseDateTimeImmutable(): void
    {
        $spec = $this->parser->parse("\DateTimeImmutable");

        $this->assertSame(ValueKind::DateTime, $spec->kind);
        $this->assertSame(DateTimeImmutable::class, $spec->phpClass);
    }

    public function testParseUuid(): void
    {
        $spec = $this->parser->parse("uuid");

        $this->assertSame(ValueKind::Uuid, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseNullableString(): void
    {
        $spec = $this->parser->parse("string|null");

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertTrue($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseNullableStringReversed(): void
    {
        $spec = $this->parser->parse("null|string");

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertTrue($spec->nullable);
        $this->assertFalse($spec->isList);
    }

    public function testParseNullableInt(): void
    {
        $spec = $this->parser->parse("int|null");

        $this->assertSame(ValueKind::Int, $spec->kind);
        $this->assertTrue($spec->nullable);
    }

    public function testParseStringArray(): void
    {
        $spec = $this->parser->parse("string[]");

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertTrue($spec->isList);
    }

    public function testParseIntArray(): void
    {
        $spec = $this->parser->parse("int[]");

        $this->assertSame(ValueKind::Int, $spec->kind);
        $this->assertTrue($spec->isList);
    }

    public function testParseNullableStringArray(): void
    {
        $spec = $this->parser->parse("string[]|null");

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertTrue($spec->nullable);
        $this->assertTrue($spec->isList);
    }

    public function testParseNullableIntArrayReversed(): void
    {
        $spec = $this->parser->parse("null|int[]");

        $this->assertSame(ValueKind::Int, $spec->kind);
        $this->assertTrue($spec->nullable);
        $this->assertTrue($spec->isList);
    }

    public function testParseDateTimeArray(): void
    {
        $spec = $this->parser->parse("datetime[]");

        $this->assertSame(ValueKind::DateTime, $spec->kind);
        $this->assertTrue($spec->isList);
    }

    public function testParseWithWhitespace(): void
    {
        $spec = $this->parser->parse("  string  ");

        $this->assertSame(ValueKind::String, $spec->kind);
    }

    public function testParseWithWhitespaceInNullable(): void
    {
        $spec = $this->parser->parse(" string | null ");

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertTrue($spec->nullable);
    }

    public function testParseCaseInsensitive(): void
    {
        $spec1 = $this->parser->parse("STRING");
        $spec2 = $this->parser->parse("String");
        $spec3 = $this->parser->parse("StRiNg");

        $this->assertSame(ValueKind::String, $spec1->kind);
        $this->assertSame(ValueKind::String, $spec2->kind);
        $this->assertSame(ValueKind::String, $spec3->kind);
    }

    public function testParseUnknownTypeThrowsException(): void
    {
        $this->expectException(UnknownTypeException::class);
        $this->expectExceptionMessage("Type not found: unknown");

        $this->parser->parse("unknown");
    }

    public function testParseEmptyStringThrowsException(): void
    {
        $this->expectException(UnknownTypeException::class);

        $this->parser->parse("");
    }

    public function testParseInvalidTypeThrowsException(): void
    {
        $this->expectException(UnknownTypeException::class);

        $this->parser->parse("array");
    }
}
