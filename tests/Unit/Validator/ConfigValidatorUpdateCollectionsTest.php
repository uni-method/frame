<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Config, JsonConfigLoader};
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * ConfigValidator::validateUpdateCollectionSetters: a collection in writable
 * of an update action with `setter: false` is a config error (PATCH replaces the collection
 * entirely, this cannot be done with `add` alone).
 *
 * ConfigValidator::validateUpdateCollectionSetters: коллекция в writable
 * update-действия с `setter: false` — ошибка конфига (PATCH заменяет коллекцию
 * целиком, одним `add` это не сделать).
 */
final class ConfigValidatorUpdateCollectionsTest extends TestCase
{
    public function testCollectionWithoutSetterIsRejectedForUpdate(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Collection relation 'tags' is writable for update on model 'post' but has 'setter: false'",
        );

        $this->load('"setter": false, "add": "addTag"', "update", "tags");
    }

    public function testCollectionWithSetterIsAcceptedForUpdate(): void
    {
        $config = $this->load('"setter": "setTags"', "update", "tags");

        self::assertInstanceOf(Config::class, $config);
    }

    public function testAddOnlyCollectionIsAcceptedForCreate(): void
    {
        $config = $this->load('"setter": false, "add": "addTag"', "create", "tags");

        self::assertInstanceOf(Config::class, $config);
    }

    public function testSingleRelationWithoutSetterIsNotAffected(): void
    {
        $config = $this->load('"setter": false', "update", "author");

        self::assertInstanceOf(Config::class, $config);
    }

    private function load(string $relationImpl, string $actionType, string $writablePath): Config
    {
        $implAction = $actionType === "update"
            ? '{ "entityLocator": "loc", "entitySaver": "saver" }'
            : '{ "entitySaver": "saver" }';

        $json = <<<JSON
        {
          "schema": {
            "models": {
              "post": {
                "attributes": { "id": { "type": "int" } },
                "relations": {
                  "tags": { "type": "oneToMany", "targetModel": "tag" },
                  "author": { "type": "manyToOne", "targetModel": "tag" }
                }
              },
              "tag": { "attributes": { "id": { "type": "int" } } }
            },
            "actions": {
              "post": { "{$actionType}": { "writable": { "{$writablePath}": "reference" } } },
              "tag": { "read": {} }
            }
          },
          "impl": {
            "models": {
              "post": {
                "class": "App\\\\Post",
                "attributes": { "id": { "getter": "getId" } },
                "relations": {
                  "tags": { "getter": "getTags", {$relationImpl} },
                  "author": { "getter": "getAuthor", {$relationImpl} }
                }
              },
              "tag": { "class": "App\\\\Tag", "attributes": { "id": { "getter": "getId" } } }
            },
            "actions": {
              "post": { "{$actionType}": {$implAction} },
              "tag": { "read": { "entityLocator": "loc" } }
            },
            "locators": { "loc": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Stub\\\\RecordingLocator" },
            "savers": { "saver": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Stub\\\\RecordingSaver" }
          }
        }
        JSON;

        return (new JsonConfigLoader())->loadFromString($json);
    }
}
