<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Config\ListAction\FilterOperator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderBoolFiltersTypeSpecTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    private function wrapJson(
        string $schemaModels,
        string $implModels,
        string $schemaActions,
    ): string {
        return <<<JSON
        {
          "schema": {
            "models": { {$schemaModels} },
            "actions": { {$schemaActions} }
          },
          "impl": {
            "models": { {$implModels} }
          }
        }
        JSON;
    }

    public function testTypeSpecBoolWithLikeOperatorThrowsException(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "flag", "operators": ["like"], "typeSpec": "bool" }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'flag' in model 'user' supports only 'Equal' operator, 'like' given",
        );

        $this->loader->loadFromString($json);
    }

    public function testTypeSpecBoolWithEqualOperatorIsValid(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "flag", "operators": ["equal"], "typeSpec": "bool" }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $filter = $config->actions[0]->config->filters[0];

        $this->assertSame("flag", $filter->path);
        $this->assertEquals([FilterOperator::Equal], $filter->operators);
    }

    public function testBoolAttributeWithoutTypeSpecAndLikeThrowsException(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" }, "active": { "type": "bool" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" }, "active": { "getter": ' .
            '"isActive" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "active", "operators": ["like"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'active' in model 'user' supports only 'Equal' operator, 'like' given",
        );

        $this->loader->loadFromString($json);
    }

    public function testStringTypeSpecOverBoolAttributeWithLikeIsValid(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" }, "active": { "type": "bool" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" }, "active": { "getter": ' .
            '"isActive" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "active", "operators": ["like"], "typeSpec": "string" }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $filter = $config->actions[0]->config->filters[0];

        $this->assertSame("active", $filter->path);
        $this->assertContains(FilterOperator::Like, $filter->operators);
    }

    public function testNonBoolTypeSpecWithAnyOperatorIsValid(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "name", "operators": ["equal", "in", "like"], "typeSpec": "string" }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);
        $filter = $config->actions[0]->config->filters[0];

        $this->assertCount(3, $filter->operators);
    }
}
