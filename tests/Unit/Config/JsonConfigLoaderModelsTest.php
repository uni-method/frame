<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * Types of attributes and relations in schema.models are checked at load, not on
 * the first request to the model.
 *
 * Типы атрибутов и связей в schema.models проверяются при загрузке, а не на
 * первом запросе к модели.
 */
final class JsonConfigLoaderModelsTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    public function testUnknownAttributeTypeThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": {
                  "id":   { "type": "int" },
                  "name": { "type": "strng" }
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
                  "name": { "getter": "getName" }
                }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessageMatches(
            "/schema\\.models\\[user\\]\\.attributes\\[name\\]\\.type' contains unknown type/",
        );

        $this->loader->loadFromString($json);
    }

    public function testUnknownRelationTypeThrows(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "friends": { "type": "oneTomany", "targetModel": "user" }
                }
              }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\Entity\\User",
                "attributes": { "id": { "getter": "getId" } },
                "relations": { "friends": { "getter": "getFriends" } }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid relation type 'oneTomany' in schema.models[user].relations[friends]",
        );

        $this->loader->loadFromString($json);
    }
}
