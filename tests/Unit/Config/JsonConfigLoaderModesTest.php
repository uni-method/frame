<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, JsonConfigLoader};

final class JsonConfigLoaderModesTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testModelWithBothLocatorAndSaver(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "user": { "read": {}, "create": {} } }
          },
          "impl": {
            "models": { "user": { "class": "App\\User", "attributes": { "id": { "getter": "getId" } } } },
            "actions": {
              "user": {
                "read":   { "entityLocator": "doctrine" },
                "create": { "entitySaver":   "doctrine" }
              }
            },
            "locators": { "doctrine": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntityLocator" },
            "savers":   { "doctrine": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntitySaver" }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        /** @var array<string, Action> $actionsByType */
        $actionsByType = [];
        foreach ($config->actions as $action) {
            $actionsByType[$action->type->value] = $action;
        }

        $this->assertSame("doctrine", $actionsByType["read"]->entityLocator);
        $this->assertSame("doctrine", $actionsByType["create"]->entitySaver);
    }

    public function testModelWithOnlyLocatorNoSaver(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "user": { "read": {} } }
          },
          "impl": {
            "models": { "user": { "class": "App\\ReadOnlyUser", "attributes": { "id": { "getter": "getId" } } } },
            "actions": { "user": { "read": { "entityLocator": "doctrine" } } },
            "locators": { "doctrine": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntityLocator" }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame("doctrine", $action->entityLocator);
        $this->assertNull($action->entitySaver);
    }

    public function testModelWithOnlySaverNoLocator(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "user": { "create": {} } }
          },
          "impl": {
            "models": { "user": { "class": "App\\CreateOnlyUser", "attributes": { "id": { "getter": "getId" } } } },
            "actions": { "user": { "create": { "entitySaver": "doctrine" } } },
            "savers": { "doctrine": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntitySaver" }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertNull($action->entityLocator);
        $this->assertSame("doctrine", $action->entitySaver);
    }

    public function testModelWithoutLocatorAndSaver(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "comment": "Data Transfer Object without persistence",
                "attributes": { "id": { "type": "int" } }
              }
            }
          },
          "impl": {
            "models": { "user": { "class": "App\\UserDTO", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $model = $config->models[0];
        $this->assertSame(
            "Data Transfer Object without persistence",
            $model->comment,
        );
        $this->assertEmpty($config->actions);
    }

    public function testMultipleModelsWithDifferentModes(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user":    { "attributes": { "id": { "type": "int" } } },
              "log":     { "attributes": { "id": { "type": "int" } } },
              "product": { "attributes": { "id": { "type": "int" } } }
            },
            "actions": {
              "user": { "read": {}, "create": {} },
              "log":  { "read": {} }
            }
          },
          "impl": {
            "models": {
              "user":    { "class": "App\\User",       "attributes": { "id": { "getter": "getId" } } },
              "log":     { "class": "App\\ReadOnlyLog", "attributes": { "id": { "getter": "getId" } } },
              "product": { "class": "App\\ProductDTO", "attributes": { "id": { "getter": "getId" } } }
            },
            "actions": {
              "user": {
                "read":   { "entityLocator": "doctrine" },
                "create": { "entitySaver":   "doctrine" }
              },
              "log": {
                "read": { "entityLocator": "elasticsearch" }
              }
            },
            "locators": {
              "doctrine":      "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntityLocator",
              "elasticsearch": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntityLocator"
            },
            "savers": { "doctrine": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntitySaver" }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(3, $config->models);

        /** @var array<string, array<string, Action>> $actionsByModelAndType */
        $actionsByModelAndType = [];
        foreach ($config->actions as $action) {
            $actionsByModelAndType[$action->model][
                $action->type->value
            ] = $action;
        }

        $this->assertSame(
            "doctrine",
            $actionsByModelAndType["user"]["read"]->entityLocator,
        );
        $this->assertSame(
            "doctrine",
            $actionsByModelAndType["user"]["create"]->entitySaver,
        );

        $this->assertSame(
            "elasticsearch",
            $actionsByModelAndType["log"]["read"]->entityLocator,
        );
        $this->assertNull($actionsByModelAndType["log"]["read"]->entitySaver);

        $this->assertArrayNotHasKey("product", $actionsByModelAndType);
    }

    public function testModelsWithDifferentLocatorsAndSameSaver(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user":    { "attributes": { "id": { "type": "int" } } },
              "product": { "attributes": { "id": { "type": "int" } } }
            },
            "actions": {
              "user":    { "read": {}, "create": {} },
              "product": { "read": {}, "create": {} }
            }
          },
          "impl": {
            "models": {
              "user":    { "class": "App\\User",    "attributes": { "id": { "getter": "getId" } } },
              "product": { "class": "App\\Product", "attributes": { "id": { "getter": "getId" } } }
            },
            "actions": {
              "user": {
                "read":   { "entityLocator": "doctrine" },
                "create": { "entitySaver":   "common" }
              },
              "product": {
                "read":   { "entityLocator": "elasticsearch" },
                "create": { "entitySaver":   "common" }
              }
            },
            "locators": {
              "doctrine":      "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntityLocator",
              "elasticsearch": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntityLocator"
            },
            "savers": { "common": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\TestEntitySaver" }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(2, $config->models);
        $this->assertCount(2, $config->locators);
        $this->assertCount(1, $config->savers);

        /** @var array<string, array<string, Action>> $actionsByModelAndType */
        $actionsByModelAndType = [];
        foreach ($config->actions as $action) {
            $actionsByModelAndType[$action->model][
                $action->type->value
            ] = $action;
        }

        $this->assertSame(
            "doctrine",
            $actionsByModelAndType["user"]["read"]->entityLocator,
        );
        $this->assertSame(
            "common",
            $actionsByModelAndType["user"]["create"]->entitySaver,
        );
        $this->assertSame(
            "elasticsearch",
            $actionsByModelAndType["product"]["read"]->entityLocator,
        );
        $this->assertSame(
            "common",
            $actionsByModelAndType["product"]["create"]->entitySaver,
        );
    }
}
