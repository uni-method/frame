<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;

final class JsonConfigLoaderTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testLoadValidConfigWithAllFields(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "comment": "User entity",
                "attributes": {
                  "id": { "type": "int", "comment": "User ID" },
                  "email": { "type": "string" }
                }
              }
            },
            "actions": {
              "user": {
                "create": {
                  "comment": "Create new user"
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id": { "getter": "getId", "setter": "setId" },
                  "email": { "getter": "getEmail", "setter": "setEmail" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->models);
        $this->assertCount(1, $config->actions);
    }

    public function testLoadValidConfigWithRelations(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "comment": "User entity",
                "attributes": {
                  "id": { "type": "int" }
                },
                "relations": {
                  "profile": { "type": "oneToOne", "targetModel": "profile", "comment": "User profile" },
                  "posts": { "type": "oneToMany", "targetModel": "post", "comment": "User posts" }
                }
              },
              "profile": {
                "attributes": { "id": { "type": "int" } }
              },
              "post": {
                "attributes": { "id": { "type": "int" } }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": {
                  "profile": { "getter": "getProfile", "setter": "setProfile" },
                  "posts": { "getter": "getPosts", "setter": "setPosts", "add": "addPost" }
                }
              },
              "profile": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } },
              "post": { "class": "App\\Entity\\Post", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(3, $config->models);
    }

    public function testLoadConfigWithAllRelationTypes(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "profile": { "type": "oneToOne", "targetModel": "profile" },
                  "posts": { "type": "oneToMany", "targetModel": "post" },
                  "company": { "type": "manyToOne", "targetModel": "company" },
                  "roles": { "type": "manyToMany", "targetModel": "role" }
                }
              },
              "profile": { "attributes": { "id": { "type": "int" } } },
              "post": { "attributes": { "id": { "type": "int" } } },
              "company": { "attributes": { "id": { "type": "int" } } },
              "role": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": {
                  "profile": { "getter": "getProfile" },
                  "posts": { "getter": "getPosts" },
                  "company": { "getter": "getCompany" },
                  "roles": { "getter": "getRoles" }
                }
              },
              "profile": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } },
              "post": { "class": "App\\Entity\\Post", "attributes": { "id": { "getter": "getId" } } },
              "company": { "class": "App\\Entity\\Company", "attributes": { "id": { "getter": "getId" } } },
              "role": { "class": "App\\Entity\\Role", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(5, $config->models);
    }

    public function testLoadConfigWithMinimalRelation(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "profile": { "type": "oneToOne", "targetModel": "profile" }
                }
              },
              "profile": {
                "attributes": { "id": { "type": "int" } }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "profile": { "getter": "getProfile" } }
              },
              "profile": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(2, $config->models);
    }

    public function testLoadConfigWithEmptyRelations(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": {}
              }
            }
          },
          "impl": {
            "models": {
              "user": { "class": "App\\Entity\\User", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(1, $config->models);
    }

    public function testLoadConfigWithoutRelations(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } }
              }
            }
          },
          "impl": {
            "models": {
              "user": { "class": "App\\Entity\\User", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(1, $config->models);
    }

    public function testLoadConfigWithMinimalFields(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "product": {
                "attributes": {
                  "id": { "type": "int" },
                  "name": { "type": "string" }
                }
              }
            },
            "actions": {
              "product": {
                "update": {}
              }
            }
          },
          "impl": {
            "models": {
              "product": {
                "class": "App\\Entity\\Product",
                "attributes": { "id": { "getter": "getId" }, "name": { "getter": "getName" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(1, $config->models);
    }

    public function testLoadConfigWithMultipleModels(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": { "attributes": { "id": { "type": "int" } } },
              "product": { "attributes": { "id": { "type": "int" } } },
              "order": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": { "class": "App\\Entity\\User", "attributes": { "id": { "getter": "getId" } } },
              "product": { "class": "App\\Entity\\Product", "attributes": { "id": { "getter": "getId" } } },
              "order": { "class": "App\\Entity\\Order", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(3, $config->models);
    }

    public function testLoadEmptyConfig(): void
    {
        $json = <<<'JSON'
        {
          "schema": {},
          "impl": {}
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(0, $config->models);
        $this->assertCount(0, $config->actions);
    }

    public function testLoadConfigWithEmptyArrays(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {},
            "actions": {}
          },
          "impl": {
            "models": {},
            "actions": {}
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(0, $config->models);
        $this->assertCount(0, $config->actions);
    }
}
