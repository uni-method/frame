<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;

final class JsonConfigLoaderConstructorTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testLoadAttributeWithConstructorParam(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "project": {
                "attributes": {
                  "id":     { "type": "string" },
                  "server": { "type": "string" },
                  "uuid":   { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "project": {
                "class": "App\\Entity\\Project",
                "attributes": {
                  "id":     { "getter": "getId" },
                  "server": { "constructorParam": true },
                  "uuid":   { "setter": "setUuid" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $model = $config->models[0];
        $this->assertCount(3, $model->attributes);

        $serverAttr = $model->attributes[1];
        $this->assertSame("server", $serverAttr->name);
        $this->assertTrue($serverAttr->constructorParam);

        $uuidAttr = $model->attributes[2];
        $this->assertSame("uuid", $uuidAttr->name);
        $this->assertFalse($uuidAttr->constructorParam);
    }

    public function testLoadRelationWithConstructorParam(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "order": {
                "attributes": {
                  "id":      { "type": "int" },
                  "orderId": { "type": "string" }
                },
                "relations": {
                  "customer": { "type": "manyToOne", "targetModel": "customer" }
                }
              },
              "customer": {
                "attributes": {
                  "id": { "type": "int" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "order": {
                "class": "App\\Entity\\Order",
                "attributes": {
                  "id":      { "getter": "getId" },
                  "orderId": { "constructorParam": true }
                },
                "relations": {
                  "customer": { "constructorParam": true }
                }
              },
              "customer": {
                "class": "Customer",
                "attributes": {
                  "id": { "getter": "getId" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $model = $config->models[0];

        $this->assertCount(1, $model->relations);
        $relation = $model->relations[0];

        $this->assertSame("customer", $relation->name);
        $this->assertTrue($relation->constructorParam);
    }

    public function testDefaultConstructorParamIsFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                },
                "relations": {
                  "profile": { "type": "oneToOne", "targetModel": "profile" }
                }
              },
              "profile": {
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
                "attributes": {
                  "id":   { "getter": "getId" },
                  "name": { "setter": "setName" }
                },
                "relations": {
                  "profile": { "setter": "setProfile" }
                }
              },
              "profile": {
                "class": "App\\Entity\\Profile",
                "attributes": {
                  "id": { "getter": "getId" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $model = $config->models[0];

        $this->assertFalse($model->attributes[1]->constructorParam);
        $this->assertFalse($model->relations[0]->constructorParam);
    }

    public function testLoadMultipleConstructorParams(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "server_config": {
                "attributes": {
                  "id":   { "type": "int" },
                  "host": { "type": "string" },
                  "port": { "type": "int" },
                  "ssl":  { "type": "bool" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "server_config": {
                "class": "App\\Entity\\ServerConfig",
                "attributes": {
                  "id":   { "getter": "getId" },
                  "host": { "constructorParam": true },
                  "port": { "constructorParam": true },
                  "ssl":  { "constructorParam": true }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $model = $config->models[0];

        $this->assertCount(4, $model->attributes);
        foreach (array_slice($model->attributes, 1) as $attr) {
            $this->assertTrue($attr->constructorParam);
        }
    }

    public function testLoadMixedConstructorAndRegularParams(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "product": {
                "attributes": {
                  "id":          { "type": "int" },
                  "name":        { "type": "string" },
                  "price":       { "type": "float" },
                  "description": { "type": "string" },
                  "createdAt":   { "type": "datetime" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "product": {
                "class": "App\\Entity\\Product",
                "attributes": {
                  "id":          { "getter": "getId" },
                  "name":        { "constructorParam": true },
                  "price":       { "constructorParam": true },
                  "description": { "setter": "setDescription" },
                  "createdAt":   { "setter": "setCreatedAt" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $model = $config->models[0];

        $this->assertTrue($model->attributes[1]->constructorParam);
        $this->assertTrue($model->attributes[2]->constructorParam);
        $this->assertFalse($model->attributes[3]->constructorParam);
        $this->assertFalse($model->attributes[4]->constructorParam);
    }

    public function testLoadComplexConfigWithConstructorParams(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "order": {
                "attributes": {
                  "id":          { "type": "int" },
                  "orderId":     { "type": "string" },
                  "totalAmount": { "type": "float" }
                },
                "relations": {
                  "customer": { "type": "manyToOne", "targetModel": "customer" },
                  "items":    { "type": "oneToMany", "targetModel": "order_item" }
                }
              },
              "customer": {
                "attributes": {
                  "id":         { "type": "int" },
                  "customerId": { "type": "string" },
                  "name":       { "type": "string" }
                }
              },
              "order_item": {
                "attributes": {
                  "id": { "type": "int" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "order": {
                "class": "App\\Entity\\Order",
                "attributes": {
                  "id":          { "getter": "getId" },
                  "orderId":     { "constructorParam": true },
                  "totalAmount": { "setter": "setTotalAmount" }
                },
                "relations": {
                  "customer": { "constructorParam": true },
                  "items":    { "setter": "setItems", "add": "addItem" }
                }
              },
              "customer": {
                "class": "App\\Entity\\Customer",
                "attributes": {
                  "id":         { "getter": "getId" },
                  "customerId": { "constructorParam": true },
                  "name":       { "constructorParam": true }
                }
              },
              "order_item": {
                "class": "App\\Entity\\OrderItem",
                "attributes": {
                  "id": { "getter": "getId" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(3, $config->models);
        $order = $config->models[0];
        $customer = $config->models[1];

        $this->assertSame("order", $order->type);
        $this->assertTrue($order->attributes[1]->constructorParam);
        $this->assertFalse($order->attributes[2]->constructorParam);
        $this->assertTrue($order->relations[0]->constructorParam);
        $this->assertFalse($order->relations[1]->constructorParam);

        foreach (array_slice($customer->attributes, 1) as $attr) {
            $this->assertTrue($attr->constructorParam);
        }
    }

    public function testConstructorParamWithFalseValue(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":   { "getter": "getId" },
                  "name": { "constructorParam": false, "setter": "setName" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertFalse($config->models[0]->attributes[1]->constructorParam);
    }

    public function testConstructorParamWithStringTrue(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":   { "getter": "getId" },
                  "name": { "constructorParam": "true" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertTrue($config->models[0]->attributes[1]->constructorParam);
    }

    public function testConstructorParamWithIntegerOne(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":   { "getter": "getId" },
                  "name": { "constructorParam": 1 }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertTrue($config->models[0]->attributes[1]->constructorParam);
    }

    public function testReadOnlyClassScenario(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "project_dto": {
                "attributes": {
                  "id":     { "type": "string" },
                  "name":   { "type": "string" },
                  "server": { "type": "string" },
                  "port":   { "type": "int" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "project_dto": {
                "class": "App\\DTO\\ProjectDto",
                "attributes": {
                  "id":     { "getter": "getId", "constructorParam": true },
                  "name":   { "constructorParam": true },
                  "server": { "constructorParam": true },
                  "port":   { "constructorParam": true }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $model = $config->models[0];

        $this->assertSame("project_dto", $model->type);
        foreach ($model->attributes as $attr) {
            $this->assertTrue($attr->constructorParam);
        }
    }
}
