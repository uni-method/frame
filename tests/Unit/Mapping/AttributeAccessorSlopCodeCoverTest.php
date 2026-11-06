<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Mapping\AttributeAccessor;
use UniMethod\Frame\Mapping\Exception\{
    GetterNotCallableException,
    SetterNotCallableException,
};

/**
 * Unit tests for AttributeAccessor
 *
 * Unit-тесты для AttributeAccessor
 */
final class AttributeAccessorSlopCodeCoverTest extends TestCase
{
    private AttributeAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new AttributeAccessor();
    }

    // ==================== getValue Tests ====================
    //
    // ==================== Тесты getValue ====================

    public function testGetValueThrowsWhenMethodIsProtected(): void
    {
        $entity = new class () {
            protected string $name = "Protected";

            protected function getName(): string
            {
                return $this->name;
            }
        };

        $this->expectException(GetterNotCallableException::class);

        $this->accessor->getValue($entity, "getName");
    }

    public function testGetValueThrowsWhenPropertyIsProtected(): void
    {
        $entity = new class () {
            protected string $secret = "protected";
        };

        $this->expectException(GetterNotCallableException::class);

        $this->accessor->getValue($entity, "secret");
    }

    // ==================== setValue Tests ====================
    //
    // ==================== Тесты setValue ====================

    public function testSetValueThrowsWhenMethodIsProtected(): void
    {
        $entity = new class () {
            protected string $name = "Secret";

            protected function setName(string $value): void
            {
                $this->name = $value;
            }
        };

        $this->expectException(SetterNotCallableException::class);

        $this->accessor->setValue($entity, "setName", "New");
    }

    public function testSetValueThrowsWhenPropertyIsProtected(): void
    {
        $entity = new class () {
            protected string $secret = "old";
        };

        $this->expectException(SetterNotCallableException::class);

        $this->accessor->setValue($entity, "secret", "new");
    }
}
