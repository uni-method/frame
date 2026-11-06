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

final class JsonConfigLoaderActionsTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    private function wrapJson(
        string $schemaActions,
        string $schemaModels = "",
        string $implModels = "",
    ): string {
        if ($schemaModels === "") {
            $schemaModels =
                '"user": { "attributes": { "id": { "type": "int" } } }';
        }

        if ($implModels === "") {
            $implModels =
                '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }';
        }

        return <<<JSON
        {
          "schema": {
            "models": { {$schemaModels} },
            "actions": { {$schemaActions} }
          },
          "impl": {
            "models": { {$implModels} }
          }
        }
        JSON;
    }

    public function testLoadActionsWithWritableAndInclude(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "create": {
                    "writable": {
                      "profile": "reference",
                      "roles": "reference"
                    },
                    "include": ["profile", "roles"]
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->actions);

        $action = $config->actions[0];
        $this->assertSame(ActionType::Create, $action->type);
        $this->assertSame("user", $action->model);

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
            RelationWriteMode::Reference,
            $action->writable["roles"]->mode,
        );
        $this->assertNull($action->writable["roles"]->access);

        $this->assertSame(["profile", "roles"], $action->include);
    }

    public function testLoadActionsWithNestedRelations(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "include": ["profile", "posts.tags", "posts.author.profile"]
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame(ActionType::Read, $action->type);
        $this->assertSame(
            ["profile", "posts.tags", "posts.author.profile"],
            $action->include,
        );
    }

    public function testInvalidActionTypeThrowsException(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "invalid": {}
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid action type 'invalid' in schema.actions[user][invalid]. Allowed values: create, read, list, " .
            "update, delete",
        );

        $this->loader->loadFromString($json);
    }

    public function testUpdateActionWithWritableIsValid(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "update": {
                    "writable": { "roles": "reference" },
                    "include": ["profile", "roles"]
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame(ActionType::Update, $action->type);
        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["roles"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["roles"]->mode,
        );
        $this->assertNull($action->writable["roles"]->access);
        $this->assertSame(["profile", "roles"], $action->include);
    }

    public function testDeleteActionWithoutIncludeIsValid(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "delete": {
                    "comment": "Удаление пользователя"
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame(ActionType::Delete, $action->type);
        $this->assertNull($action->writable);
        $this->assertNull($action->include);
    }

    public function testActionTypeEnumMethods(): void
    {
        $this->assertTrue(ActionType::Create->canWrite());
        $this->assertTrue(ActionType::Update->canWrite());
        $this->assertFalse(ActionType::Read->canWrite());
        $this->assertFalse(ActionType::List->canWrite());
        $this->assertTrue(ActionType::Delete->canWrite());
    }

    public function testComplexActionConfiguration(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "post": {
                  "update": {
                    "writable": {
                      "tags": "reference",
                      "categories": "reference"
                    },
                    "include": ["author.profile", "author.roles", "tags", "categories.parent"],
                    "comment": "Обновление поста с вложенными связями"
                  }
                }
            JSON
            ,
            '"post": { "attributes": { "id": { "type": "int" } } }',
            '"post": { "class": "App\\\\Post", "attributes": { "id": { "getter": "getId" } } }',
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame(ActionType::Update, $action->type);
        $this->assertSame("post", $action->model);

        $this->assertCount(2, $action->writable);
        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["tags"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["tags"]->mode,
        );
        $this->assertInstanceOf(
            WritableConfig::class,
            $action->writable["categories"],
        );
        $this->assertEquals(
            RelationWriteMode::Reference,
            $action->writable["categories"]->mode,
        );

        $this->assertSame(
            ["author.profile", "author.roles", "tags", "categories.parent"],
            $action->include,
        );
        $this->assertSame(
            "Обновление поста с вложенными связями",
            $action->comment,
        );
    }

    public function testLoadActionWithIncludableIsNull(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "include": ["profile"]
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->actions[0]->includable);
    }

    public function testLoadActionWithIncludableNoAccess(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "includable": {
                      "profile": null,
                      "posts": null,
                      "posts.tags": null
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertIsArray($action->includable);
        $this->assertCount(3, $action->includable);
        $this->assertArrayHasKey("profile", $action->includable);
        $this->assertArrayHasKey("posts", $action->includable);
        $this->assertArrayHasKey("posts.tags", $action->includable);
        $this->assertNull($action->includable["profile"]);
        $this->assertNull($action->includable["posts"]);
        $this->assertNull($action->includable["posts.tags"]);
    }

    public function testLoadActionWithIncludableAndAccessConfig(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "includable": {
                      "profile": null,
                      "adminLogs": { "authRequired": true, "roles": ["admin"] }
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertIsArray($action->includable);
        $this->assertCount(2, $action->includable);

        $this->assertNull($action->includable["profile"]);

        $this->assertInstanceOf(
            AccessConfig::class,
            $action->includable["adminLogs"],
        );
        $this->assertTrue($action->includable["adminLogs"]->authRequired);
        $this->assertSame(["admin"], $action->includable["adminLogs"]->roles);
    }

    public function testLoadActionWithIncludableMixedAccess(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "post": {
                  "list": {
                    "includable": {
                      "author": null,
                      "tags": null,
                      "internalMeta": { "authRequired": true },
                      "auditLog": { "authRequired": true, "roles": ["admin", "auditor"] }
                    }
                  }
                }
            JSON
            ,
            '"post": { "attributes": { "id": { "type": "int" } } }',
            '"post": { "class": "App\\\\Post", "attributes": { "id": { "getter": "getId" } } }',
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertCount(4, $action->includable);

        $this->assertNull($action->includable["author"]);
        $this->assertNull($action->includable["tags"]);

        $this->assertInstanceOf(
            AccessConfig::class,
            $action->includable["internalMeta"],
        );
        $this->assertTrue($action->includable["internalMeta"]->authRequired);
        $this->assertNull($action->includable["internalMeta"]->roles);

        $this->assertInstanceOf(
            AccessConfig::class,
            $action->includable["auditLog"],
        );
        $this->assertTrue($action->includable["auditLog"]->authRequired);
        $this->assertSame(
            ["admin", "auditor"],
            $action->includable["auditLog"]->roles,
        );
    }

    public function testLoadActionWithIncludableTrivialAccessResolvesToNull(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "includable": {
                      "profile": { "authRequired": false }
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertNull($action->includable["profile"]);
    }

    public function testLoadActionWithIncludableInvalidStructureThrows(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "includable": ["profile", "posts"]
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[user][read].includable' must be an object or null",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadActionIncludableAndIncludeAreIndependent(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "read": {
                    "include": ["profile"],
                    "includable": {
                      "profile": null,
                      "posts": { "authRequired": true }
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertSame(["profile"], $action->include);
        $this->assertCount(2, $action->includable);
        $this->assertNull($action->includable["profile"]);
        $this->assertInstanceOf(
            AccessConfig::class,
            $action->includable["posts"],
        );
    }
}
