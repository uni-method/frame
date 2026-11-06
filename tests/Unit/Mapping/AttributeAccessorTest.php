<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Mapping\AttributeAccessor;
use UniMethod\Frame\Mapping\Exception\{
    AttributeNotFoundException,
    GetterNotCallableException,
    SetterNotCallableException,
};

/**
 * Unit tests for AttributeAccessor
 *
 * Unit-тесты для AttributeAccessor
 */
final class AttributeAccessorTest extends TestCase
{
    private AttributeAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new AttributeAccessor();
    }

    // ==================== resolveGetter Tests ====================
    //
    // ==================== Тесты resolveGetter ====================

    public function testResolveGetterReturnsStringWhenProvided(): void
    {
        $entity = new class () {
            public function customGetter(): string
            {
                return "value";
            }
        };

        $result = $this->accessor->resolveGetter(
            $entity,
            "customGetter",
            "name",
        );

        $this->assertSame("customGetter", $result);
    }

    public function testResolveGetterReturnsFalseWhenExplicitlyDisabled(): void
    {
        $entity = new class () {
            public string $name = "test";
        };

        $result = $this->accessor->resolveGetter($entity, false, "name");

        $this->assertFalse($result);
    }

    public function testResolveGetterAutodetectsPublicProperty(): void
    {
        $entity = new class () {
            public string $email = "test@example.com";
        };

        $result = $this->accessor->resolveGetter($entity, null, "email");

        $this->assertSame("email", $result);
    }

    public function testResolveGetterReturnsNullWhenPropertyNotFound(): void
    {
        $entity = new class () {
        };

        $result = $this->accessor->resolveGetter($entity, null, "hidden");

        $this->assertNull($result);
    }

    public function testResolveGetterReturnsNullWhenPropertyDoesNotExist(): void
    {
        $entity = new class () {
        };

        $result = $this->accessor->resolveGetter($entity, null, "nonexistent");

        $this->assertNull($result);
    }

    // ==================== resolveSetter Tests ====================
    //
    // ==================== Тесты resolveSetter ====================

    public function testResolveSetterReturnsStringWhenProvided(): void
    {
        $entity = new class () {
            public function customSetter(string $v): void
            {
            }
        };

        $result = $this->accessor->resolveSetter(
            $entity,
            "customSetter",
            "name",
        );

        $this->assertSame("customSetter", $result);
    }

    public function testResolveSetterReturnsFalseWhenExplicitlyDisabled(): void
    {
        $entity = new class () {
            public string $name = "test";
        };

        $result = $this->accessor->resolveSetter($entity, false, "name");

        $this->assertFalse($result);
    }

    public function testResolveSetterAutodetectsPublicProperty(): void
    {
        $entity = new class () {
            public string $title = "Default";
        };

        $result = $this->accessor->resolveSetter($entity, null, "title");

        $this->assertSame("title", $result);
    }

    // ==================== getValue Tests ====================
    //
    // ==================== Тесты getValue ====================

    public function testGetValueReadsFromMethod(): void
    {
        $entity = new class () {
            private string $name = "John Doe";

            public function getName(): string
            {
                return $this->name;
            }
        };

        $value = $this->accessor->getValue($entity, "getName");

        $this->assertSame("John Doe", $value);
    }

    public function testGetValueReadsFromPublicProperty(): void
    {
        $entity = new class () {
            public string $title = "Article Title";
        };

        $value = $this->accessor->getValue($entity, "title");

        $this->assertSame("Article Title", $value);
    }

    public function testGetValueThrowsWhenMethodIsPrivate(): void
    {
        $entity = new class () {
            private string $name = "Secret";

            // @phpstan-ignore method.unused (test case reason)
            private function getName(): string
            {
                return $this->name;
            }
        };

        $this->expectException(GetterNotCallableException::class);

        $this->accessor->getValue($entity, "getName");
    }

    public function testGetValueThrowsWhenPropertyIsPrivate(): void
    {
        $entity = new class () {
            // @phpstan-ignore property.onlyWritten (test case reason)
            private string $secret = "classified";
        };

        $this->expectException(GetterNotCallableException::class);

        $this->accessor->getValue($entity, "secret");
    }

    public function testGetValueThrowsWhenAttributeNotFound(): void
    {
        $entity = new class () {
        };

        $this->expectException(AttributeNotFoundException::class);

        $this->accessor->getValue($entity, "nonexistent");
    }

    public function testGetValueThrowsWhenMethodHasRequiredParameters(): void
    {
        $entity = new class () {
            public function getName(string $prefix): string
            {
                return $prefix;
            }
        };

        $this->expectException(GetterNotCallableException::class);
        $this->expectExceptionMessageMatches("/must take no parameters/i");

        $this->accessor->getValue($entity, "getName");
    }

    public function testGetValueHandlesUninitializedProperty(): void
    {
        $entity = new class () {
            // Not initialized
            //
            // Не инициализировано
            public string $name;
        };

        $this->expectException(GetterNotCallableException::class);

        $this->accessor->getValue($entity, "name");
    }

    // ==================== setValue Tests ====================
    //
    // ==================== Тесты setValue ====================

    public function testSetValueWritesToMethod(): void
    {
        $entity = new class () {
            private string $name = "Old";

            public function setName(string $value): void
            {
                $this->name = $value;
            }

            public function getName(): string
            {
                return $this->name;
            }
        };

        $this->accessor->setValue($entity, "setName", "New Name");

        $this->assertSame("New Name", $entity->getName());
    }

    public function testSetValueWritesToPublicProperty(): void
    {
        $entity = new class () {
            public string $title = "Original";
        };

        $this->accessor->setValue($entity, "title", "Updated");

        $this->assertSame("Updated", $entity->title);
    }

    public function testSetValueThrowsWhenMethodIsPrivate(): void
    {
        $entity = new class () {
            // @phpstan-ignore property.onlyWritten (test case reason)
            private string $name = "Secret";

            // @phpstan-ignore method.unused (test case reason)
            private function setName(string $value): void
            {
                $this->name = $value;
            }
        };

        $this->expectException(SetterNotCallableException::class);

        $this->accessor->setValue($entity, "setName", "New");
    }

    public function testSetValueThrowsWhenPropertyIsPrivate(): void
    {
        $entity = new class () {
            // @phpstan-ignore property.onlyWritten (test case reason)
            private string $secret = "old";
        };

        $this->expectException(SetterNotCallableException::class);

        $this->accessor->setValue($entity, "secret", "new");
    }

    public function testSetValueThrowsWhenAttributeNotFound(): void
    {
        $entity = new class () {
        };

        $this->expectException(AttributeNotFoundException::class);

        $this->accessor->setValue($entity, "nonexistent", "value");
    }

    public function testSetValueThrowsWhenMethodRequiresDifferentParameterCount(): void
    {
        $entity = new class () {
            public function setName(string $value, bool $force): void
            {
                // Method expects 2 parameters
                //
                // Метод ожидает 2 параметра
            }
        };

        $this->expectException(SetterNotCallableException::class);
        $this->expectExceptionMessageMatches(
            "/must take exactly 1 parameter/i",
        );

        $this->accessor->setValue($entity, "setName", "value");
    }

    public function testSetValueHandlesDifferentDataTypes(): void
    {
        $entity = new class () {
            public int $age = 0;

            public float $price = 0.0;

            public bool $active = false;

            /** @var list<string> */
            public array $tags = [];
        };

        $this->accessor->setValue($entity, "age", 25);
        $this->accessor->setValue($entity, "price", 99.99);
        $this->accessor->setValue($entity, "active", true);
        $this->accessor->setValue($entity, "tags", ["php", "testing"]);

        $this->assertSame(25, $entity->age);
        $this->assertSame(99.99, $entity->price);
        $this->assertTrue($entity->active);
        $this->assertSame(["php", "testing"], $entity->tags);
    }

    // ==================== Integration Tests ====================
    //
    // ==================== Интеграционные тесты ====================

    public function testGetValueAndSetValueWorkTogether(): void
    {
        $entity = new class () {
            private string $name = "Initial";

            public function getName(): string
            {
                return $this->name;
            }

            public function setName(string $value): void
            {
                $this->name = $value;
            }
        };

        $original = $this->accessor->getValue($entity, "getName");
        $this->assertSame("Initial", $original);

        $this->accessor->setValue($entity, "setName", "Modified");

        $updated = $this->accessor->getValue($entity, "getName");
        $this->assertSame("Modified", $updated);
    }

    public function testMixedAccessors(): void
    {
        $entity = new class () {
            public string $status = "pending";

            public function getStatus(): string
            {
                return $this->status;
            }

            // No setStatus - only a public property
            //
            // Нет setStatus - только публичное свойство
        };

        // Read via the method
        //
        // Читаем через метод
        $value = $this->accessor->getValue($entity, "getStatus");
        $this->assertSame("pending", $value);

        // Write via the property
        //
        // Пишем через свойство
        $this->accessor->setValue($entity, "status", "approved");

        // Check via the method
        //
        // Проверяем через метод
        $updated = $this->accessor->getValue($entity, "getStatus");
        $this->assertSame("approved", $updated);
    }

    public function testAutodetectionWithUninitializedPublicProperty(): void
    {
        $entity = new class () {
            public string $name;
        };

        // Auto-detection must find the property
        //
        // Автоопределение должно найти свойство
        $getter = $this->accessor->resolveGetter($entity, null, "name");
        $this->assertSame("name", $getter);

        // But reading will throw an exception because it is not initialized
        //
        // Но чтение выбросит исключение, т.к. не инициализировано
        $this->expectException(GetterNotCallableException::class);
        $this->accessor->getValue($entity, "name");
    }

    public function testReadonlyPropertyCanBeRead(): void
    {
        $entity = new class () {
            public readonly string $id;

            public function __construct()
            {
                $this->id = "abc-123";
            }
        };

        $value = $this->accessor->getValue($entity, "id");
        $this->assertSame("abc-123", $value);
    }

    public function testProtectedPropertyIsNotDetected(): void
    {
        $entity = new class () {
            protected string $internal = "hidden";
        };

        $getter = $this->accessor->resolveGetter($entity, null, "internal");
        $this->assertNull(
            $getter,
            "Protected property should not be autodetected",
        );
    }
}
