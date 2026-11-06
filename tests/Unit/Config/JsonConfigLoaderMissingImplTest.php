<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderMissingImplTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testModelOnlyInSchemaThrowsValidationError(): void
    {
        $json = <<<'JSON'
        {
          "schema": { "models": { "user": { "attributes": { "id": { "type": "int" } } } } },
          "impl": {}
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "schema.models[user] has no corresponding model in impl",
        );

        $this->loader->loadFromString($json);
    }

    public function testAttributeOnlyInSchemaThrowsValidationError(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id": { "type": "int" },
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

    public function testRelationOnlyInSchemaThrowsValidationError(): void
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
                "attributes": { "id": { "getter": "getId" } }
              },
              "profile": {
                "class": "App\\Entity\\Profile",
                "attributes": { "id": { "getter": "getId" } }
              }
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

    public function testCompleteImplLoadsSuccessfully(): void
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
              "profile": {
                "class": "App\\Entity\\Profile",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertCount(2, $config->models);
    }
}
