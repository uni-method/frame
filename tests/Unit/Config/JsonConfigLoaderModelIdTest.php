<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderModelIdTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testModelWithoutIdThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "email": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "email": { "getter": "getEmail" } }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'user' must define an attribute 'id' with a getter",
        );

        $this->loader->loadFromString($json);
    }

    public function testModelWithIdButNoGetterThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "product": {
                "attributes": {
                  "id": { "type": "int" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "product": {
                "class": "App\\Entity\\Product",
                "attributes": { "id": { "getter": false } }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'product' must define an attribute 'id' with a getter",
        );

        $this->loader->loadFromString($json);
    }

    public function testModelWithIdAndGetterIsValid(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "order": {
                "attributes": {
                  "id":    { "type": "int" },
                  "total": { "type": "float" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "order": {
                "class": "App\\Entity\\Order",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "total": { "getter": "getTotal" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(1, $config->models);
        $this->assertSame("order", $config->models[0]->type);
    }

    public function testModelWithIdGetterFalseThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "invoice": {
                "attributes": {
                  "id": { "type": "int" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "invoice": {
                "class": "App\\Entity\\Invoice",
                "attributes": { "id": { "getter": false } }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'invoice' must define an attribute 'id' with a getter",
        );

        $this->loader->loadFromString($json);
    }

    /**
     * The getter for `id` is not set (`null`) — auto-detection by the entity,
     * as for any attribute; only an explicit `false` is forbidden.
     *
     * Getter для `id` не задан (`null`) — автоопределение по сущности,
     * как для любого атрибута; запрещён только явный `false`.
     */
    public function testModelWithIdAutodetectedGetterIsValid(): void
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
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": {} }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertNull($config->models[0]->attributes[0]->getter);
    }
}
