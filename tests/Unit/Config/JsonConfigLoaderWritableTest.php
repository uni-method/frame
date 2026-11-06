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
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderWritableTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    private function wrapUser(string $actionBody): string
    {
        return <<<JSON
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "user": { {$actionBody} } }
          },
          "impl": {
            "models": { "user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;
    }

    private function wrapOrder(string $actionBody): string
    {
        return <<<JSON
        {
          "schema": {
            "models": { "order": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "order": { {$actionBody} } }
          },
          "impl": {
            "models": { "order": { "class": "App\\\\Order", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;
    }

    public function testLoadWritableAsListOfStrings(): void
    {
        $json = $this->wrapUser(
            '"create": { "writable": ["profile", "roles"] }',
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame(ActionType::Create, $action->type);
        $this->assertIsArray($action->writable);
        $this->assertArrayHasKey("profile", $action->writable);
        $this->assertArrayHasKey("roles", $action->writable);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["profile"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["profile"]->mode,
        );
        $this->assertNull($action->writable["profile"]->access);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["roles"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["roles"]->mode,
        );
        $this->assertNull($action->writable["roles"]->access);
    }

    public function testLoadWritableAsObjectWithModes(): void
    {
        $json = $this->wrapUser(
            '"update": { "writable": { "profile": "reference", "roles": "create" } }',
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertSame(ActionType::Update, $action->type);
        $this->assertCount(2, $action->writable);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["profile"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["profile"]->mode,
        );
        $this->assertNull($action->writable["profile"]->access);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["roles"],
        );
        $this->assertEquals(
            RelationWriteMode::Create,
            $action->writable["roles"]->mode,
        );
        $this->assertNull($action->writable["roles"]->access);
    }

    public function testLoadWritableWithAccessConfig(): void
    {
        $json = $this->wrapUser(
            '"update": { "writable": { "profile": "reference", "adminSection": { "mode": "reference", "access": { ' .
            '"authRequired": true, "roles": ["admin"] } } } }',
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertCount(2, $action->writable);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["profile"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["profile"]->mode,
        );
        $this->assertNull($action->writable["profile"]->access);

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["adminSection"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["adminSection"]->mode,
        );
        $this->assertInstanceOf(
            AccessConfig::class,
            $action->writable["adminSection"]->access,
        );
        $this->assertTrue(
            $action->writable["adminSection"]->access->authRequired,
        );
        $this->assertSame(
            ["admin"],
            $action->writable["adminSection"]->access->roles,
        );
    }

    public function testLoadWritableWithAccessConfigCreateMode(): void
    {
        $json = $this->wrapOrder(
            '"create": { "writable": { "items": { "mode": "create", "access": { "authRequired": true } } } }',
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
        $this->assertInstanceOf(
            AccessConfig::class,
            $action->writable["items"]->access,
        );
        $this->assertTrue($action->writable["items"]->access->authRequired);
        $this->assertNull($action->writable["items"]->access->roles);
    }

    public function testLoadWritableWithTrivialAccessConfigResolvesToNull(): void
    {
        $json = $this->wrapUser(
            '"create": { "writable": { "profile": { "mode": "reference", "access": { "authRequired": false } } } }',
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["profile"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["profile"]->mode,
        );
        $this->assertNull($action->writable["profile"]->access);
    }

    public function testLoadWritableExtendedFormatWithInvalidModeThrows(): void
    {
        $json = $this->wrapUser(
            '"update": { "writable": { "profile": { "mode": "invalid" } } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid write mode 'invalid' in schema.actions[user][update].writable[profile]. Allowed values: " .
            "reference, create",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadWritableExtendedFormatMissingModeThrows(): void
    {
        $json = $this->wrapUser('"update": { "writable": { "profile": {} } }');

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Mode for relation 'profile' in 'schema.actions[user][update].writable' must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadWritableMixedInvalidStructureThrows(): void
    {
        $json = $this->wrapUser(
            '"update": { "writable": { "profile": "not-an-array" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid write mode 'not-an-array' in schema.actions[user][update].writable[profile]. Allowed values: " .
            "reference, create",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadWritableWithInvalidModeThrows(): void
    {
        $json = $this->wrapUser(
            '"update": { "writable": { "profile": ["invalid-mode"] } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Mode for relation 'profile' in 'schema.actions[user][update].writable' must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadWritableWithEmptyRelationKeyThrows(): void
    {
        $json = $this->wrapUser(
            '"create": { "writable": { "": ["reference"] } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid relation key in 'schema.actions[user][create].writable': must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadWritableNullIsValid(): void
    {
        $json = $this->wrapUser('"update": { "writable": null }');

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->actions[0]->writable);
    }

    public function testLoadWritableWithWhitespaceModeThrows(): void
    {
        $json = $this->wrapUser(
            '"update": { "writable": { "profile": ["   "] } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Mode for relation 'profile' in 'schema.actions[user][update].writable' must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }
}
