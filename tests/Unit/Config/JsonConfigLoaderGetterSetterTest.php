<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Tests\Unit\Stub\User;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderGetterSetterTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testLoadAttributeWithoutImplThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":    { "type": "int" },
                  "email": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].attributes[email] has no corresponding attribute in impl",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadAttributeWithGetterSetterFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":       { "type": "int" },
                  "password": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":       { "getter": "getId" },
                  "password": { "getter": false, "setter": "setPassword" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertSame("password", $attribute->name);
        $this->assertFalse($attribute->getter);
        $this->assertSame("setPassword", $attribute->setter);
    }

    public function testLoadAttributeWithGetterSetterStringFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":           { "type": "int" },
                  "internalData": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":           { "getter": "getId" },
                  "internalData": { "getter": "false", "setter": "false" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertFalse($attribute->getter);
        $this->assertFalse($attribute->setter);
    }

    public function testLoadAttributeWithReadOnly(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":        { "type": "int" },
                  "createdAt": { "type": "DateTime" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":        { "getter": "getId" },
                  "createdAt": { "getter": "getCreatedAt", "setter": false }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertSame("createdAt", $attribute->name);
        $this->assertSame("getCreatedAt", $attribute->getter);
        $this->assertFalse($attribute->setter);
    }

    public function testLoadAttributeWithWriteOnly(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":       { "type": "int" },
                  "password": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":       { "getter": "getId" },
                  "password": { "getter": false, "setter": "setPassword" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertFalse($attribute->getter);
        $this->assertSame("setPassword", $attribute->setter);
    }

    public function testLoadRelationWithoutImplThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": { "profile": { "type": "oneToOne", "targetModel": "profile" } }
              },
              "profile": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user":    { "class": "App\\Entity\\User",    "attributes": { "id": { "getter": "getId" } } },
              "profile": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user].relations[profile] has no corresponding relation in impl",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadRelationWithGetterSetterFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": { "hiddenRelation": { "type": "oneToOne", "targetModel": "secret" } }
              },
              "secret": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "hiddenRelation": { "getter": false, "setter": false } }
              },
              "secret": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $relation = $config->models[0]->relations[0];
        $this->assertFalse($relation->getter);
        $this->assertFalse($relation->setter);
    }

    public function testLoadRelationWithAddFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": { "posts": { "type": "oneToMany", "targetModel": "post" } }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "posts": { "getter": "getPosts", "setter": "setPosts", "add": false } }
              },
              "post": { "class": "App\\Entity\\Post", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $relation = $config->models[0]->relations[0];
        $this->assertSame("getPosts", $relation->getter);
        $this->assertSame("setPosts", $relation->setter);
        $this->assertFalse($relation->add);
    }

    public function testLoadRelationWithAddStringFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": { "posts": { "type": "oneToMany", "targetModel": "post" } }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "posts": { "getter": "getPosts", "add": "false" } }
              },
              "post": { "class": "App\\Entity\\Post", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $relation = $config->models[0]->relations[0];
        $this->assertSame("posts", $relation->name);
        $this->assertSame("getPosts", $relation->getter);
        $this->assertNull($relation->setter);
        $this->assertFalse($relation->add);
    }

    public function testLoadComplexModelWithMixedGetterSetter(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":            { "type": "int",      "comment": "Primary key" },
                  "email":         { "type": "string",   "comment": "User email" },
                  "password":      { "type": "string",   "comment": "Write-only password" },
                  "createdAt":     { "type": "DateTime", "comment": "Read-only timestamp" },
                  "internalCache": { "type": "string[]", "comment": "Internal cache, not accessible" }
                },
                "relations": {
                  "profile":         { "type": "oneToOne",  "targetModel": "profile" },
                  "posts":           { "type": "oneToMany", "targetModel": "post" },
                  "roles":           { "type": "manyToMany","targetModel": "role" },
                  "hiddenRelation":  { "type": "oneToOne",  "targetModel": "secret" }
                }
              },
              "profile": { "attributes": { "id": { "type": "int" } } },
              "post":    { "attributes": { "id": { "type": "int" } } },
              "role":    { "attributes": { "id": { "type": "int" } } },
              "secret":  { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\ComplexUser",
                "attributes": {
                  "id":            { "getter": "getId" },
                  "email":         { "getter": "getEmail",    "setter": "setEmail" },
                  "password":      { "getter": false,         "setter": "setPassword" },
                  "createdAt":     { "getter": "getCreatedAt","setter": false },
                  "internalCache": { "getter": false,         "setter": false }
                },
                "relations": {
                  "profile":        {},
                  "posts":          { "getter": "getPosts", "setter": "setPosts", "add": "addPost" },
                  "roles":          { "getter": "getRoles", "setter": "setRoles", "add": false },
                  "hiddenRelation": { "getter": false, "setter": false }
                }
              },
              "profile": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } },
              "post":    { "class": "App\\Entity\\Post",    "attributes": { "id": { "getter": "getId" } } },
              "role":    { "class": "App\\Entity\\Role",    "attributes": { "id": { "getter": "getId" } } },
              "secret":  { "class": "App\\Entity\\Secret",  "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(5, $config->models);

        $model = $config->models[0];
        $this->assertSame("user", $model->type);

        $this->assertCount(5, $model->attributes);

        $idAttr = $model->attributes[0];
        $this->assertSame("id", $idAttr->name);
        $this->assertSame("getId", $idAttr->getter);
        $this->assertNull($idAttr->setter);

        $emailAttr = $model->attributes[1];
        $this->assertSame("email", $emailAttr->name);
        $this->assertSame("getEmail", $emailAttr->getter);
        $this->assertSame("setEmail", $emailAttr->setter);

        $passwordAttr = $model->attributes[2];
        $this->assertSame("password", $passwordAttr->name);
        $this->assertFalse($passwordAttr->getter);
        $this->assertSame("setPassword", $passwordAttr->setter);

        $createdAtAttr = $model->attributes[3];
        $this->assertSame("createdAt", $createdAtAttr->name);
        $this->assertSame("getCreatedAt", $createdAtAttr->getter);
        $this->assertFalse($createdAtAttr->setter);

        $cacheAttr = $model->attributes[4];
        $this->assertSame("internalCache", $cacheAttr->name);
        $this->assertFalse($cacheAttr->getter);
        $this->assertFalse($cacheAttr->setter);

        $this->assertCount(4, $model->relations);

        $profileRel = $model->relations[0];
        $this->assertSame("profile", $profileRel->name);
        $this->assertNull($profileRel->getter);
        $this->assertNull($profileRel->setter);

        $postsRel = $model->relations[1];
        $this->assertSame("posts", $postsRel->name);
        $this->assertSame("getPosts", $postsRel->getter);
        $this->assertSame("setPosts", $postsRel->setter);
        $this->assertSame("addPost", $postsRel->add);

        $rolesRel = $model->relations[2];
        $this->assertSame("roles", $rolesRel->name);
        $this->assertSame("getRoles", $rolesRel->getter);
        $this->assertSame("setRoles", $rolesRel->setter);
        $this->assertFalse($rolesRel->add);

        $hiddenRel = $model->relations[3];
        $this->assertSame("hiddenRelation", $hiddenRel->name);
        $this->assertFalse($hiddenRel->getter);
        $this->assertFalse($hiddenRel->setter);
    }

    public function testLoadAttributeWithOnlyGetter(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":       { "type": "int",    "comment": "Auto-detected public property" },
                  "fullName": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":       { "getter": "getId" },
                  "fullName": { "getter": "getFullName", "setter": false }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $attribute = $config->models[0]->attributes[1];
        $this->assertSame("fullName", $attribute->name);
        $this->assertSame("getFullName", $attribute->getter);
        $this->assertFalse($attribute->setter);
    }

    public function testLoadAttributeWithOnlySetter(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":        { "type": "int",    "comment": "Auto-detected public property" },
                  "secretKey": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":        { "getter": "getId" },
                  "secretKey": { "getter": false, "setter": "setSecretKey" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $attribute = $config->models[0]->attributes[1];
        $this->assertSame("secretKey", $attribute->name);
        $this->assertFalse($attribute->getter);
        $this->assertSame("setSecretKey", $attribute->setter);
    }

    public function testLoadRelationWithReadOnly(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "order": {
                "attributes": { "id": { "type": "int" } },
                "relations": { "items": { "type": "oneToMany", "targetModel": "order_item" } }
              },
              "order_item": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "order": {
                "class": "App\\Entity\\Order",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "items": { "getter": "getItems", "setter": false, "add": false } }
              },
              "order_item": { "class": "App\\Entity\\OrderItem", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $relation = $config->models[0]->relations[0];
        $this->assertSame("items", $relation->name);
        $this->assertSame("getItems", $relation->getter);
        $this->assertFalse($relation->setter);
        $this->assertFalse($relation->add);
    }

    public function testLoadMultipleModelsWithMixedGetterSetter(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "public_user": {
                "comment": "User with public properties",
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                }
              },
              "private_user": {
                "comment": "User with private properties",
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                }
              },
              "hidden_user": {
                "comment": "User with hidden fields",
                "attributes": {
                  "id":         { "type": "int" },
                  "secretData": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "public_user": {
                "class": "App\\Entity\\PublicUser",
                "attributes": {
                  "id":   { "getter": "getId" },
                  "name": { "getter": "getName" }
                }
              },
              "private_user": {
                "class": "App\\Entity\\PrivateUser",
                "attributes": {
                  "id":   { "getter": "getId", "setter": false },
                  "name": { "getter": "getName", "setter": "setName" }
                }
              },
              "hidden_user": {
                "class": "App\\Entity\\HiddenUser",
                "attributes": {
                  "id":         { "getter": "getId" },
                  "secretData": { "getter": false, "setter": false }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(3, $config->models);

        $publicUser = $config->models[0];
        $this->assertNotNull($publicUser->attributes[0]->getter);
        $this->assertNull($publicUser->attributes[0]->setter);
        $this->assertSame("getName", $publicUser->attributes[1]->getter);
        $this->assertNull($publicUser->attributes[1]->setter);

        $privateUser = $config->models[1];
        $this->assertSame("getId", $privateUser->attributes[0]->getter);
        $this->assertFalse($privateUser->attributes[0]->setter);
        $this->assertSame("getName", $privateUser->attributes[1]->getter);
        $this->assertSame("setName", $privateUser->attributes[1]->setter);

        $hiddenUser = $config->models[2];
        $this->assertNotNull($hiddenUser->attributes[0]->getter);
    }

    public function testLoadAttributeWithMutatorSetter(): void
    {
        $mutatorClass = "TestHideBalanceMutator_" . uniqid();
        eval(
            sprintf(
                '
            class %s implements \UniMethod\Frame\Mutator\AttributeMutatorInterface {
                public function apply(
                    \UniMethod\Frame\Config\PhaseType $phase,
                    object $entity,
                    \UniMethod\Frame\Config\Model $model,
                    string $attribute,
                    mixed $value,
                    \UniMethod\Frame\Config\ActionType $actionType
                ): mixed { return $value; }
            }
        ',
                $mutatorClass,
            )
        );

        $json = sprintf(
            <<<'JSON'
            {
              "schema": {
                "models": {
                  "user": {
                    "attributes": {
                      "id":      { "type": "int" },
                      "balance": {
                        "type": "int|null",
                        "writable": false,
                        "mutators": { "beforeOutput": ["hideBalance"] }
                      }
                    }
                  }
                }
              },
              "impl": {
                "models": {
                  "user": {
                    "class": "App\\Entity\\User",
                    "attributes": {
                      "id":      { "getter": "getId" },
                      "balance": { "getter": "getBalance", "mutatorSetter": "setBalanceMutator" }
                    }
                  }
                },
                "mutators": { "hideBalance": "%s" }
              }
            }
            JSON
            ,
            $mutatorClass,
        );

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertSame("balance", $attribute->name);
        $this->assertSame("getBalance", $attribute->getter);
        $this->assertFalse($attribute->setter);
        $this->assertSame("setBalanceMutator", $attribute->mutatorSetter);
    }

    public function testLoadAttributeWithMutatorSetterFalse(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":      { "type": "int" },
                  "balance": { "type": "int|null" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":      { "getter": "getId" },
                  "balance": { "getter": "getBalance", "mutatorSetter": false }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertSame("balance", $attribute->name);
        $this->assertFalse($attribute->mutatorSetter);
    }

    public function testLoadAttributeWithMutatorSetterAbsentIsNull(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":    { "type": "int" },
                  "email": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "email": { "getter": "getEmail", "setter": "setEmail" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $attribute = $config->models[0]->attributes[1];
        $this->assertNull($attribute->mutatorSetter);
    }

    public function testLoadRelationWithMutatorSetter(): void
    {
        $mutatorClass = "TestHideProfileMutator_" . uniqid();
        eval(
            sprintf(
                '
            class %s implements \UniMethod\Frame\Mutator\RelationMutatorInterface {
                public function apply(
                    \UniMethod\Frame\Config\PhaseType $phase,
                    object $entity,
                    \UniMethod\Frame\Config\Model $model,
                    string $relation,
                    mixed $value,
                    \UniMethod\Frame\Config\ActionType $actionType
                ): mixed { return $value; }
            }
        ',
                $mutatorClass,
            )
        );

        $json = sprintf(
            <<<'JSON'
            {
              "schema": {
                "models": {
                  "user": {
                    "attributes": { "id": { "type": "int" } },
                    "relations": {
                      "profile": {
                        "type": "oneToOne",
                        "targetModel": "profile",
                        "mutators": { "beforeOutput": ["hideProfile"] }
                      }
                    }
                  },
                  "profile": { "attributes": { "id": { "type": "int" } } }
                }
              },
              "impl": {
                "models": {
                  "user": {
                    "class": "App\\Entity\\User",
                    "attributes": { "id": { "getter": "getId" } },
                    "relations": {
                      "profile": { "getter": "getProfile", "setter": false, "mutatorSetter": "setProfileForMutator" }
                    }
                  },
                  "profile": { "class": "App\\Entity\\Profile", "attributes": { "id": { "getter": "getId" } } }
                },
                "relationMutators": { "hideProfile": "%s" }
              }
            }
            JSON
            ,
            $mutatorClass,
        );

        $config = $this->loader->loadFromString($json);
        $relation = $config->models[0]->relations[0];
        $this->assertSame("profile", $relation->name);
        $this->assertSame("getProfile", $relation->getter);
        $this->assertFalse($relation->setter);
        $this->assertSame("setProfileForMutator", $relation->mutatorSetter);
    }

    public function testLoadRelationWithOnlyAdd(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "collection": {
                "attributes": { "id": { "type": "int" } },
                "relations": { "items": { "type": "oneToMany", "targetModel": "item" } }
              },
              "item": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "collection": {
                "class": "App\\Entity\\Collection",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "items": { "getter": "items", "setter": false, "add": "addItem" } }
              },
              "item": { "class": "App\\Entity\\Item", "attributes": { "id": { "getter": "getId" } } }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $relation = $config->models[0]->relations[0];
        $this->assertSame("items", $relation->name);
        $this->assertSame("items", $relation->getter);
        $this->assertFalse($relation->setter);
        $this->assertSame("addItem", $relation->add);
    }

    /**
     * Accessor identifier — according to PHP rules (`[a-zA-Z_\x80-\xff]...` at
     * the byte level): Cyrillic is allowed and is preserved as is.
     *
     * Идентификатор аксессора — по правилам PHP (`[a-zA-Z_\x80-\xff]...` на
     * уровне байтов): кириллица допустима и сохраняется как есть.
     */
    public function testLoadAttributeWithCyrillicGetter(): void
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
                  "name": { "getter": "имя" }
                }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertSame("имя", $config->models[0]->attributes[1]->getter);
        // PHP declares and calls such a method
        //
        // Такой метод PHP объявляет и вызывает
        $this->assertSame("x", (new User(name: "x"))->{"имя"}());
    }
}
