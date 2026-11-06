<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    AccessConfig,
    ActionType,
    JsonConfigLoader,
    RelationWriteMode,
    WritableConfig,
};

final class JsonConfigLoaderWritableModesTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    private function wrap(
        string $schemaActions,
        string $schemaModel = "order",
    ): string {
        return <<<JSON
        {
          "schema": {
            "models": { "{$schemaModel}": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "{$schemaModel}": { {$schemaActions} } }
          },
          "impl": {
            "models": { "{$schemaModel}": { "class": "App\\\\Model", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;
    }

    public function testLoadWritableWithReferenceMode(): void
    {
        $json = $this->wrap(
            '"create": { "writable": { "customer": "reference" } }',
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertSame(ActionType::Create, $action->type);
        $this->assertArrayHasKey("customer", $action->writable);
        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["customer"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["customer"]->mode,
        );
        $this->assertNull($action->writable["customer"]->access);
    }

    public function testLoadWritableWithMultipleReferences(): void
    {
        $json = $this->wrap(
            '"create": { "writable": { "customer": "reference", "warehouse": "reference", "paymentMethod": ' .
            '"reference" } }',
        );

        $config = $this->loader->loadFromString($json);
        $this->assertCount(3, $config->actions[0]->writable);
    }

    public function testLoadWritableWithCreateMode(): void
    {
        $json = $this->wrap('"create": { "writable": { "items": "create" } }');

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["items"],
        );
        $this->assertEquals(
            RelationWriteMode::Create,
            $action->writable["items"]->mode,
        );
    }

    public function testLoadWritableWithReferenceAndCreate(): void
    {
        $json = $this->wrap(
            '"create": { "writable": { "customer": "reference", "items": "create" } }',
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["customer"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["customer"]->mode,
        );
        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["items"],
        );
        $this->assertEquals(
            RelationWriteMode::Create,
            $action->writable["items"]->mode,
        );
    }

    public function testLoadWritableComplexNestedWithMixedModes(): void
    {
        $json = $this->wrap(
            '"create": { "writable": { "customer": "reference", "items": "create", "items.product": "reference", ' .
            '"shippingAddress": "create", "shippingAddress.country": "reference" } }',
        );

        $config = $this->loader->loadFromString($json);
        $this->assertCount(5, $config->actions[0]->writable);
    }

    public function testLoadWritableLegacyFormatDefaultsToReference(): void
    {
        $json = $this->wrap(
            '"create": { "writable": ["profile", "roles", "company"] }',
            "user",
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        foreach (["profile", "roles", "company"] as $key) {
            $this->assertInstanceOf(
                WritableConfig::class,
                $action->writable[$key],
            );
            $this->assertEquals(
                RelationWriteMode::Reference,
                $action->writable[$key]->mode,
            );
            $this->assertNull($action->writable[$key]->access);
        }
    }

    public function testLoadWritablePreservesOrderOfModes(): void
    {
        $json = $this->wrap(
            '"update": { "writable": { "items": "create" } }',
            "resource",
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["items"],
        );
        $this->assertEquals(
            RelationWriteMode::Create,
            $action->writable["items"]->mode,
        );
    }

    public function testLoadWritableMixedShortAndExtendedFormats(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "order": { "attributes": { "id": { "type": "int" } } } },
            "actions": {
              "order": {
                "create": {
                  "writable": {
                    "customer": "reference",
                    "items": "create",
                    "adminItems": {
                      "mode": "create",
                      "access": { "authRequired": true, "roles": ["admin", "manager"] }
                    }
                  }
                }
              }
            }
          },
          "impl": {
            "models": { "order": { "class": "App\\Order", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertCount(3, $action->writable);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["customer"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["customer"]->mode,
        );
        $this->assertNull($action->writable["customer"]->access);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["items"],
        );
        $this->assertEquals(
            RelationWriteMode::Create,
            $action->writable["items"]->mode,
        );
        $this->assertNull($action->writable["items"]->access);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["adminItems"],
        );
        $this->assertEquals(
            RelationWriteMode::Create,
            $action->writable["adminItems"]->mode,
        );
        $this->assertInstanceOf(
            AccessConfig::class,
            $action->writable["adminItems"]->access,
        );
        $this->assertTrue(
            $action->writable["adminItems"]->access->authRequired,
        );
        $this->assertSame(
            ["admin", "manager"],
            $action->writable["adminItems"]->access->roles,
        );
    }
}
