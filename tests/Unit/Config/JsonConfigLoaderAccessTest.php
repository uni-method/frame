<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;

final class JsonConfigLoaderAccessTest extends TestCase
{
    private JsonConfigLoader $loader;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Config\Stub\Valid2User")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Config\Stub;
                use UniMethod\Frame\Integration\UserInterface;
                class Valid2User implements UserInterface {
                    public function getId(): int { return 1; }
                    public function getUserIdentifier(): string|int { return 1; }
                    public function getRoles(): array { return []; }
                    public function hasRole(string $role): bool { return true; }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testLoadActionWithAccessConfig(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id": { "type": "int" }
                }
              }
            },
            "actions": {
              "item": {
                "create": {
                  "access": {
                    "authRequired": true,
                    "roles": ["admin", "editor"]
                  }
                }
              }
            }
          },
          "impl": {
            "userModel": "user",
            "models": {
              "user": {
                "class": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\Valid2User",
                "attributes": {
                  "id": { "getter": "getId" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertNotNull($action->access);
        $this->assertTrue($action->access->authRequired);
        $this->assertSame(["admin", "editor"], $action->access->roles);
    }

    public function testActionWithAnonymousAccessCreatesNoAccessConfig(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "post": {
                "attributes": {
                  "id": { "type": "int" }
                }
              }
            },
            "actions": {
              "post": {
                "list": {
                  "access": {
                    "authRequired": false
                  }
                }
              }
            }
          },
          "impl": {
            "models": {
              "post": {
                "class": "App\\Post",
                "attributes": {
                  "id": { "getter": "getId" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $action = $config->actions[0];

        $this->assertNull($action->access);
    }
}
