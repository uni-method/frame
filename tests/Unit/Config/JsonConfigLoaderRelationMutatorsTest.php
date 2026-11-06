<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderRelationMutatorsTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();

        if (!class_exists("App\\Mutator\\SortRelationMutator")) {
            eval('
                namespace App\\Mutator;
                use UniMethod\\Frame\\Mutator\\RelationMutatorInterface;
                use UniMethod\\Frame\\Config\\PhaseType;
                use UniMethod\\Frame\\Config\\Model;
                use UniMethod\\Frame\\Config\\ActionType;

                class SortRelationMutator implements RelationMutatorInterface {
                    public function apply(
                        PhaseType $phase,
                        object $entity,
                        Model $model,
                        string $relation,
                        mixed $value,
                        ActionType $actionType,
                    ): mixed {
                        if (is_array($value)) { sort($value); }
                        return $value;
                    }
                    public function getSubscribedPhases(): array { return [PhaseType::BeforeOutput]; }
                }
            ');
        }

        if (!class_exists("App\\Mutator\\FilterRelationMutator")) {
            eval('
                namespace App\\Mutator;
                use UniMethod\\Frame\\Mutator\\RelationMutatorInterface;
                use UniMethod\\Frame\\Config\\PhaseType;
                use UniMethod\\Frame\\Config\\Model;
                use UniMethod\\Frame\\Config\\ActionType;

                class FilterRelationMutator implements RelationMutatorInterface {
                    public function apply(
                        PhaseType $phase,
                        object $entity,
                        Model $model,
                        string $relation,
                        mixed $value,
                        ActionType $actionType,
                    ): mixed {
                        return $value;
                    }
                    public function getSubscribedPhases(): array { return [PhaseType::AfterInput]; }
                }
            ');
        }
    }

    public function testValidRelationMutatorConfigurationIsLoaded(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": {
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "string" }
                },
                "relations": {
                  "posts": {
                    "type": "oneToMany",
                    "targetModel": "post",
                    "mutators": { "beforeOutput": ["sortPosts"] }
                  }
                }
              },
              "post": {
                "attributes": {
                  "id":    { "type": "int" },
                  "title": { "type": "string" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": {
                  "id":   { "getter": "getId" },
                  "name": { "getter": "getName" }
                },
                "relations": {
                  "posts": { "getter": "getPosts", "setter": "setPosts" }
                }
              },
              "post": {
                "class": "App\\Post",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "title": { "getter": "getTitle" }
                }
              }
            },
            "relationMutators": {
              "sortPosts": "App\\Mutator\\SortRelationMutator"
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->relationMutators);
        $this->assertArrayHasKey("sortPosts", $config->relationMutators);
        $this->assertSame(
            "App\\Mutator\\SortRelationMutator",
            $config->relationMutators["sortPosts"],
        );

        $postsRelation = $config->models[0]->relations[0];
        $this->assertNotNull($postsRelation->mutators);
        $this->assertSame(
            ["sortPosts"],
            $postsRelation->mutators->beforeOutput,
        );
    }

    public function testUndefinedRelationMutatorAliasThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "posts": {
                    "type": "oneToMany",
                    "targetModel": "post",
                    "mutators": { "beforeOutput": ["undefinedAlias"] }
                  }
                }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "posts": { "getter": "getPosts", "setter": "setPosts" } }
              },
              "post": {
                "class": "App\\Post",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {
              "sortPosts": "App\\Mutator\\SortRelationMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined relation mutator alias 'undefinedAlias'",
        );

        $this->loader->loadFromString($json);
    }

    public function testRelationMutatorWithDisabledSetterThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "posts": {
                    "type": "oneToMany",
                    "targetModel": "post",
                    "mutators": { "beforeOutput": ["sortPosts"] }
                  }
                }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "posts": { "getter": "getPosts", "setter": false } }
              },
              "post": {
                "class": "App\\Post",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {
              "sortPosts": "App\\Mutator\\SortRelationMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but has setter explicitly disabled",
        );

        $this->loader->loadFromString($json);
    }

    public function testRelationMutatorWithConstructorParamThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "posts": {
                    "type": "oneToMany",
                    "targetModel": "post",
                    "mutators": { "afterInput": ["filterPosts"] }
                  }
                }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } },
                "relations": {
                  "posts": { "getter": "getPosts", "setter": "setPosts", "constructorParam": true }
                }
              },
              "post": {
                "class": "App\\Post",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {
              "filterPosts": "App\\Mutator\\FilterRelationMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "uses mutators but is defined as constructorParam",
        );

        $this->loader->loadFromString($json);
    }

    public function testInvalidRelationMutatorClassThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {
              "invalid": "App\\Mutator\\NonExistingRelationClass"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Relation mutator 'invalid' refers to undefined class 'App\\Mutator\\NonExistingRelationClass'",
        );

        $this->loader->loadFromString($json);
    }

    public function testRelationMutatorClassNotImplementingInterfaceThrows(): void
    {
        if (!class_exists("App\\Mutator\\BadRelationMutator")) {
            eval("namespace App\\Mutator; class BadRelationMutator {}");
        }

        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {
              "bad": "App\\Mutator\\BadRelationMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Relation mutator 'bad' class 'App\\Mutator\\BadRelationMutator' must implement RelationMutatorInterface",
        );

        $this->loader->loadFromString($json);
    }

    public function testEmptyRelationMutatorsArrayIsValid(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {}
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertEmpty($config->relationMutators);
    }

    public function testRelationMutatorsNotSpecifiedIsValid(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertEmpty($config->relationMutators);
    }

    public function testRelationWithMultiplePhaseMutators(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "posts": {
                    "type": "oneToMany",
                    "targetModel": "post",
                    "mutators": {
                      "afterInput":    ["filterPosts"],
                      "afterValidate": [],
                      "beforeOutput":  ["sortPosts"]
                    }
                  }
                }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "posts": { "getter": "getPosts", "setter": "setPosts" } }
              },
              "post": {
                "class": "App\\Post",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "relationMutators": {
              "sortPosts":   "App\\Mutator\\SortRelationMutator",
              "filterPosts": "App\\Mutator\\FilterRelationMutator"
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $postsRelation = $config->models[0]->relations[0];

        $this->assertSame(
            ["filterPosts"],
            $postsRelation->mutators->afterInput,
        );
        $this->assertSame([], $postsRelation->mutators->afterValidate);
        $this->assertSame(
            ["sortPosts"],
            $postsRelation->mutators->beforeOutput,
        );
    }

    public function testRelationWithoutMutatorsHasNullMutators(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "author": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "posts": { "type": "oneToMany", "targetModel": "post" }
                }
              },
              "post": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "author": {
                "class": "App\\Author",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "posts": { "getter": "getPosts" } }
              },
              "post": {
                "class": "App\\Post",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $postsRelation = $config->models[0]->relations[0];
        $this->assertNull($postsRelation->mutators);
    }
}
