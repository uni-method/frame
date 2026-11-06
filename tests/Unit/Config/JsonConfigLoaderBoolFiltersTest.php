<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{ActionType, JsonConfigLoader};
use UniMethod\Frame\Config\ListAction\FilterOperator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderBoolFiltersTest extends TestCase
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
        string $implActions = "",
    ): string {
        $implActionsJson =
            $implActions !== ""
                ? sprintf(', "actions": { %s }', $implActions)
                : "";

        return <<<JSON
        {
          "schema": {
            "models": { {$schemaModels} },
            "actions": { {$schemaActions} }
          },
          "impl": {
            "models": { {$implModels} }{$implActionsJson}
          }
        }
        JSON;
    }

    // ==================== Bool Filter Validation Tests ====================
    //
    // ==================== Тесты валидации bool-фильтров ====================

    public function testBoolFilterWithEqualOperatorIsValid(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" }, "isActive": { "type": "bool" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" }, "isActive": { "getter": ' .
            '"isActive" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "isActive", "operators": ["equal"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertSame(ActionType::List, $action->type);
        $this->assertNotNull($action->config);
        $this->assertCount(1, $action->config->filters);
        $this->assertSame("isActive", $action->config->filters[0]->path);
        $this->assertEquals(
            [FilterOperator::Equal],
            $action->config->filters[0]->operators,
        );
    }

    public function testBoolFilterWithBooleanTypeIsValid(): void
    {
        $json = $this->wrapJson(
            '"post": { "attributes": { "id": { "type": "int" }, "published": { "type": "boolean" } } }',
            '"post": { "class": "App\\\\Post", "attributes": { "id": { "getter": "getId" }, "published": { "getter": ' .
            '"isPublished" } } }',
            <<<'JSON'
                "post": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "published", "operators": ["equal"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $filter = $config->actions[0]->config->filters[0];
        $this->assertSame("published", $filter->path);
        $this->assertEquals([FilterOperator::Equal], $filter->operators);
    }

    public function testBoolFilterWithInOperatorThrowsException(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" }, "isActive": { "type": "bool" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" }, "isActive": { "getter": ' .
            '"isActive" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "isActive", "operators": ["in"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'isActive' in model 'user' supports only 'Equal' operator, 'in' given",
        );

        $this->loader->loadFromString($json);
    }

    public function testBoolFilterWithRangeOperatorThrowsException(): void
    {
        $json = $this->wrapJson(
            '"product": { "attributes": { "id": { "type": "int" }, "inStock": { "type": "boolean" } } }',
            '"product": { "class": "App\\\\Product", "attributes": { "id": { "getter": "getId" }, "inStock": { ' .
            '"getter": "isInStock" } } }',
            <<<'JSON'
                "product": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "inStock", "operators": ["range"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'inStock' in model 'product' supports only 'Equal' operator, 'range' given",
        );

        $this->loader->loadFromString($json);
    }

    public function testBoolFilterWithLikeOperatorThrowsException(): void
    {
        $json = $this->wrapJson(
            '"article": { "attributes": { "id": { "type": "int" }, "featured": { "type": "bool" } } }',
            '"article": { "class": "App\\\\Article", "attributes": { "id": { "getter": "getId" }, "featured": { ' .
            '"getter": "isFeatured" } } }',
            <<<'JSON'
                "article": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "featured", "operators": ["like"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'featured' in model 'article' supports only 'Equal' operator, 'like' given",
        );

        $this->loader->loadFromString($json);
    }

    /**
     * A path through a relation resolves to an attribute of the target model: the bool attribute
     * `address.active` allows only equal, just like a direct one.
     *
     * Путь через связь резолвится до атрибута целевой модели: bool-атрибут
     * `address.active` допускает только equal, как и прямой.
     */
    public function testBoolFilterThroughRelationWithLikeOperatorThrowsException(): void
    {
        $json = $this->wrapJson(
            <<<'JSON'
                "user": {
                  "attributes": { "id": { "type": "int" } },
                  "relations": { "address": { "type": "oneToOne", "targetModel": "address" } }
                },
                "address": {
                  "attributes": { "id": { "type": "int" }, "active": { "type": "bool" } }
                }
            JSON
            ,
            <<<'JSON'
                "user": {
                  "class": "App\\User",
                  "attributes": { "id": { "getter": "getId" } },
                  "relations": { "address": { "getter": "getAddress" } }
                },
                "address": {
                  "class": "App\\Address",
                  "attributes": { "id": { "getter": "getId" }, "active": { "getter": "isActive" } }
                }
            JSON
            ,
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "address.active", "operators": ["like"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'address.active' in model 'user' supports only 'Equal' operator, 'like' given",
        );

        $this->loader->loadFromString($json);
    }

    public function testBoolFilterWithMultipleOperatorsFirstIsInvalidThrowsException(): void
    {
        $json = $this->wrapJson(
            '"task": { "attributes": { "id": { "type": "int" }, "completed": { "type": "bool" } } }',
            '"task": { "class": "App\\\\Task", "attributes": { "id": { "getter": "getId" }, "completed": { "getter": ' .
            '"isCompleted" } } }',
            <<<'JSON'
                "task": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "completed", "operators": ["in", "equal"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Boolean filter 'completed' in model 'task' supports only 'Equal' operator, 'in' given",
        );

        $this->loader->loadFromString($json);
    }

    public function testNonBoolFilterWithInOperatorIsValid(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" }, "status": { "type": "string" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" }, "status": { "getter": ' .
            '"getStatus" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "status", "operators": ["equal", "in", "like"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $filter = $config->actions[0]->config->filters[0];
        $this->assertSame("status", $filter->path);
        $this->assertCount(3, $filter->operators);
    }

    public function testMixedFiltersWithBoolAndNonBoolIsValid(): void
    {
        $json = $this->wrapJson(
            '"order": { "attributes": { "id": { "type": "int" }, "paid": { "type": "bool" }, "status": { "type": ' .
            '"string" } } }',
            '"order": { "class": "App\\\\Order", "attributes": { "id": { "getter": "getId" }, "paid": { "getter": ' .
            '"isPaid" }, "status": { "getter": "getStatus" } } }',
            <<<'JSON'
                "order": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "paid", "operators": ["equal"] },
                        { "path": "status", "operators": ["equal", "in", "like"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $filters = $config->actions[0]->config->filters;
        $this->assertCount(2, $filters);
        $this->assertSame("paid", $filters[0]->path);
        $this->assertEquals([FilterOperator::Equal], $filters[0]->operators);
        $this->assertSame("status", $filters[1]->path);
        $this->assertCount(3, $filters[1]->operators);
    }

    public function testBoolFilterCaseInsensitiveType(): void
    {
        $json = $this->wrapJson(
            '"settings": { "attributes": { "id": { "type": "int" }, "enabled": { "type": "BOOL" } } }',
            '"settings": { "class": "App\\\\Settings", "attributes": { "id": { "getter": "getId" }, "enabled": { ' .
            '"getter": "isEnabled" } } }',
            <<<'JSON'
                "settings": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "enabled", "operators": ["equal"] }
                      ]
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $filter = $config->actions[0]->config->filters[0];
        $this->assertSame("enabled", $filter->path);
    }

    public function testBoolFilterBooleanCaseInsensitiveType(): void
    {
        $json = $this->wrapJson(
            '"feature": { "attributes": { "id": { "type": "int" }, "active": { "type": "Boolean" } } }',
            '"feature": { "class": "App\\\\Feature", "attributes": { "id": { "getter": "getId" }, "active": { ' .
            '"getter": "isActive" } } }',
            <<<'JSON'
                "feature": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "active", "operators": ["equal"] }
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
    }

    // ==================== ListActionConfig Class Property Tests ====================
    //
    // ==================== Тесты свойства class у ListActionConfig ====================

    public function testLoadListActionConfigWithClass(): void
    {
        $json = $this->wrapJson(
            '"post": { "attributes": { "id": { "type": "int" } } }',
            '"post": { "class": "App\\\\Post", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "post": {
                  "list": {
                    "config": {
                      "pagination": { "maxSize": 100, "defaultSize": 20 }
                    }
                  }
                }
            JSON
            ,
            '"post": { "list": { "config": { "class": "App\\\\Query\\\\PostListQuery" } } }',
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertNotNull($action->config);
        $this->assertSame("App\\Query\\PostListQuery", $action->config->class);
    }

    public function testLoadListActionConfigWithNullClass(): void
    {
        $json = $this->wrapJson(
            '"user": { "attributes": { "id": { "type": "int" } } }',
            '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "user": {
                  "list": {
                    "config": {
                      "class": null,
                      "pagination": { "defaultSize": 50 }
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertNull($listConfig->class);
    }

    public function testLoadListActionConfigWithoutClassField(): void
    {
        $json = $this->wrapJson(
            '"product": { "attributes": { "id": { "type": "int" } } }',
            '"product": { "class": "App\\\\Product", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "product": {
                  "list": {
                    "config": {
                      "pagination": { "defaultSize": 30 }
                    }
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertNull($listConfig->class);
    }

    public function testLoadListActionConfigWithClassAndFilters(): void
    {
        $json = $this->wrapJson(
            '"order": { "attributes": { "id": { "type": "int" }, "status": { "type": "string" } } }',
            '"order": { "class": "App\\\\Order", "attributes": { "id": { "getter": "getId" }, "status": { "getter": ' .
            '"getStatus" } } }',
            <<<'JSON'
                "order": {
                  "list": {
                    "config": {
                      "filters": [
                        { "path": "status", "operators": ["equal", "in"] }
                      ]
                    }
                  }
                }
            JSON
            ,
            '"order": { "list": { "config": { "class": "App\\\\Query\\\\OrderListQuery" } } }',
        );

        $config = $this->loader->loadFromString($json);

        $listConfig = $config->actions[0]->config;
        $this->assertSame("App\\Query\\OrderListQuery", $listConfig->class);
        $this->assertCount(1, $listConfig->filters);
    }

    public function testComplexListActionConfigWithAllFields(): void
    {
        $json = $this->wrapJson(
            '"article": { "attributes": { "id": { "type": "int" }, "published": { "type": "bool" }, "title": { ' .
            '"type": "string" }, "createdAt": { "type": "datetime" } } }',
            '"article": { "class": "App\\\\Article", "attributes": { "id": { "getter": "getId" }, "published": { ' .
            '"getter": "isPublished" }, "title": { "getter": "getTitle" }, "createdAt": { "getter": "getCreatedAt" } ' .
            '} }',
            <<<'JSON'
                "article": {
                  "list": {
                    "config": {
                      "pagination": { "maxSize": 200, "defaultSize": 25 },
                      "sort": [{ "path": "createdAt", "defaultDirection": "desc" }],
                      "filters": [
                        { "path": "published", "operators": ["equal"] },
                        { "path": "title", "operators": ["like", "equal"] }
                      ]
                    }
                  }
                }
            JSON
            ,
            '"article": { "list": { "config": { "class": "App\\\\Query\\\\ArticleListQuery" } } }',
        );

        $config = $this->loader->loadFromString($json);

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertSame("App\\Query\\ArticleListQuery", $listConfig->class);
        $this->assertNotNull($listConfig->pagination);
        $this->assertSame(200, $listConfig->pagination->maxSize);
        $this->assertCount(1, $listConfig->sort);
        $this->assertCount(2, $listConfig->filters);

        // Check that a bool filter is validated correctly
        //
        // Проверка, что bool фильтр валидируется корректно
        $this->assertSame("published", $listConfig->filters[0]->path);
        $this->assertEquals(
            [FilterOperator::Equal],
            $listConfig->filters[0]->operators,
        );
    }

    public function testEmptyListActionConfigHasNullClass(): void
    {
        $json = $this->wrapJson(
            '"category": { "attributes": { "id": { "type": "int" } } }',
            '"category": { "class": "App\\\\Category", "attributes": { "id": { "getter": "getId" } } }',
            <<<'JSON'
                "category": {
                  "list": {
                    "config": {}
                  }
                }
            JSON
            ,
        );

        $config = $this->loader->loadFromString($json);

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertNull($listConfig->class);
        $this->assertNull($listConfig->pagination);
        $this->assertEmpty($listConfig->sort);
        $this->assertEmpty($listConfig->filters);
    }
}
