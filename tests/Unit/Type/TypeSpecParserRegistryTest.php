<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Type;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Type\{
    TypeDef,
    TypeRegistry,
    TypeSpecParser,
    UnknownTypeException,
    ValueKind,
};

final class TypeSpecParserRegistryTest extends TestCase
{
    private TypeSpecParser $parser;

    protected function setUp(): void
    {
        $this->parser = new TypeSpecParser(new TypeRegistry([
            new TypeDef(name: 'money', kind: ValueKind::Int),
            new TypeDef(name: 'order_status', kind: ValueKind::String, enum: ['new', 'paid']),
        ]));
    }

    public function testBuiltInTypesStillParseWithoutRegistryLookup(): void
    {
        $spec = $this->parser->parse('string|null');

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertTrue($spec->nullable);
        $this->assertNull($spec->declaredName);
    }

    public function testRegisteredTypeResolvesToItsKind(): void
    {
        $spec = $this->parser->parse('money');

        $this->assertSame(ValueKind::Int, $spec->kind);
        $this->assertFalse($spec->nullable);
        $this->assertFalse($spec->isList);
        $this->assertSame('money', $spec->declaredName);
    }

    public function testRegisteredTypeSupportsListAndNullableModifiers(): void
    {
        $spec = $this->parser->parse('money[]|null');

        $this->assertSame(ValueKind::Int, $spec->kind);
        $this->assertTrue($spec->nullable);
        $this->assertTrue($spec->isList);
        $this->assertSame('money', $spec->declaredName);
    }

    public function testRegisteredTypeLookupIsCaseInsensitive(): void
    {
        $spec = $this->parser->parse('Order_Status');

        $this->assertSame(ValueKind::String, $spec->kind);
        $this->assertSame('order_status', $spec->declaredName);
    }

    public function testUnknownTypeStillThrows(): void
    {
        $this->expectException(UnknownTypeException::class);
        $this->expectExceptionMessage('Type not found: address');

        $this->parser->parse('address');
    }

    public function testParserWithoutRegistryThrowsOnDeclaredTypes(): void
    {
        $this->expectException(UnknownTypeException::class);

        (new TypeSpecParser())->parse('money');
    }
}
