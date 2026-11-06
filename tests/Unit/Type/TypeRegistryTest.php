<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Type;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Type\{TypeDef, TypeRegistry, ValueKind};

final class TypeRegistryTest extends TestCase
{
    public function testFindReturnsRegisteredDef(): void
    {
        $money = new TypeDef(name: 'money', kind: ValueKind::Int);
        $registry = new TypeRegistry([$money]);

        $this->assertSame($money, $registry->find('money'));
    }

    public function testFindIsCaseInsensitive(): void
    {
        $def = new TypeDef(name: 'OrderStatus', kind: ValueKind::String, enum: ['new', 'paid']);
        $registry = new TypeRegistry([$def]);

        $this->assertSame($def, $registry->find('orderstatus'));
        $this->assertSame($def, $registry->find('ORDERSTATUS'));
    }

    public function testFindReturnsNullForUnknownType(): void
    {
        $registry = new TypeRegistry();

        $this->assertNull($registry->find('money'));
    }
}
