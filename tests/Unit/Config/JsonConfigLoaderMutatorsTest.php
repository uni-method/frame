<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderMutatorsTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();

        if (!class_exists("App\\Mutator\\TrimMutator")) {
            eval('
        namespace App\\Mutator;
        use UniMethod\\Frame\\Mutator\\AttributeMutatorInterface;
        use UniMethod\\Frame\\Config\\PhaseType;
        use UniMethod\\Frame\\Config\\Model;
        use UniMethod\\Frame\\Config\\ActionType;

        class TrimMutator implements AttributeMutatorInterface {
            public function apply(
                PhaseType $phase,
                object $entity,
                Model $model,
                string $attribute,
                mixed $value,
                ActionType $actionType,
            ): mixed {
                return is_string($value) ? trim($value) : $value;
            }
            public function getSubscribedPhases(): array {
                return [PhaseType::beforeOutput];
            }
        }
    ');
        }
    }

    public function testValidMutatorConfigurationIsLoaded(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":    { "type": "int" },
                  "email": { "type": "string", "mutators": { "beforeOutput": ["trim"] } }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "email": { "getter": "getEmail", "setter": "setEmail" }
                }
              }
            },
            "mutators": {
              "trim": "App\\Mutator\\TrimMutator"
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->mutators);
        $this->assertArrayHasKey("trim", $config->mutators);
        $this->assertSame(
            "App\\Mutator\\TrimMutator",
            $config->mutators["trim"],
        );

        $emailAttr = $config->models[0]->attributes[1];
        $this->assertNotNull($emailAttr->mutators);
        $this->assertSame(["trim"], $emailAttr->mutators->beforeOutput);
    }

    public function testUndefinedMutatorAliasThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":    { "type": "int" },
                  "email": { "type": "string", "mutators": { "beforeOutput": ["undefinedAlias"] } }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "email": { "getter": "getEmail", "setter": "setEmail" }
                }
              }
            },
            "mutators": {
              "trim": "App\\Mutator\\TrimMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "references undefined mutator alias 'undefinedAlias'",
        );

        $this->loader->loadFromString($json);
    }

    public function testMissingSetterThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":    { "type": "int" },
                  "email": { "type": "string", "mutators": { "beforeOutput": ["trim"] } }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "email": { "getter": "getEmail" }
                }
              }
            },
            "mutators": {
              "trim": "App\\Mutator\\TrimMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("uses mutators but has no valid setter");

        $this->loader->loadFromString($json);
    }

    public function testConstructorParamWithMutatorsThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":    { "type": "int" },
                  "email": { "type": "string", "mutators": { "beforeOutput": ["trim"] } }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": {
                  "id":    { "getter": "getId" },
                  "email": { "getter": "getEmail", "setter": "setEmail", "constructorParam": true }
                }
              }
            },
            "mutators": {
              "trim": "App\\Mutator\\TrimMutator"
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

    public function testInvalidMutatorClassThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "mutators": {
              "invalid": "App\\Mutator\\NonExistingClass"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Mutator 'invalid' refers to undefined class 'App\\Mutator\\NonExistingClass'",
        );

        $this->loader->loadFromString($json);
    }

    public function testMutatorClassNotImplementingInterfaceThrows(): void
    {
        if (!class_exists("App\\Mutator\\BadMutator")) {
            eval("namespace App\\Mutator; class BadMutator {}");
        }

        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "mutators": {
              "bad": "App\\Mutator\\BadMutator"
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Mutator 'bad' class 'App\\Mutator\\BadMutator' must implement AttributeMutatorInterface",
        );

        $this->loader->loadFromString($json);
    }

    public function testEmptyMutatorsArrayIsValid(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\UserDTO",
                "attributes": { "id": { "getter": "getId" } }
              }
            },
            "mutators": {}
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertEmpty($config->mutators);
    }

    public function testMutatorsNotSpecifiedIsValid(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\UserDTO",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertEmpty($config->mutators);
    }
}
