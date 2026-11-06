<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{ActionType, JsonConfigLoader};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    SortDirection,
};
use UniMethod\Frame\Config\ListAction\PaginationRule;
use UniMethod\Frame\Tests\Unit\Config\Stub\ValidRepository;
use UniMethod\Frame\Type\ValueKind;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderListActionTest extends TestCase
{
    private JsonConfigLoader $loader;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Config\Stub\InvalidUser")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Config\Stub;
                class InvalidUser {
                    public function getId(): int { return 1; }
                }
            ');
        }

        if (!class_exists("UniMethod\Frame\Tests\Unit\Config\Stub\ValidUser")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Config\Stub;
                use UniMethod\Frame\Integration\UserInterface;
                class ValidUser implements UserInterface {
                    public function getId(): int { return 1; }
                    public function getUserIdentifier(): string|int { return 1; }
                    public function getRoles(): array { return []; }
                    public function hasRole(string $role): bool { return true; }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Config\Stub\InvalidRepository",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Config\Stub;
                class InvalidRepository {
                    public function list(
                        object|null $filter = null,
                        object|null $sort = null,
                        object|null $pagination = null,
                    ): array { return []; }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Config\Stub\ValidRepository",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Config\Stub;
                use UniMethod\Frame\Persistence\EntityCollectionInterface;
                class ValidRepository implements EntityCollectionInterface {
                    public function list(
                        object|null $filter = null,
                        object|null $sort = null,
                        object|null $pagination = null,
                        array $included = [],
                    ): array { return []; }
                    public function countForList(object|null $filter = null): int { return 0; }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    //
    // ── хелперы ───────────────────────────────────────────────────────────────

    /**
     * Simple post schema + impl with optional schema actions body.
     *
     * Простая схема post + impl с необязательным телом schema actions.
     */
    private function postJson(
        string $schemaActionsBody = '"list": {}',
        string $implActionsBody = "",
    ): string {
        $implActions =
            $implActionsBody !== ""
                ? ', "actions": { "post": { ' . $implActionsBody . " } }"
                : "";

        return <<<JSON
        {
          "schema": {
            "models": {
              "post": {
                "attributes": {
                  "id": { "type": "int" },
                  "createdAt": { "type": "datetime" },
                  "title": { "type": "string" }
                }
              }
            },
            "actions": { "post": { {$schemaActionsBody} } }
          },
          "impl": {
            "models": {
              "post": {
                "class": "App\\\\Post",
                "attributes": {
                  "id": { "getter": "getId" },
                  "createdAt": { "getter": "getCreatedAt" },
                  "title": { "getter": "getTitle" }
                }
              }
            }
            {$implActions}
          }
        }
        JSON;
    }

    // ==================== UserModel Tests ====================
    //
    // ==================== Тесты UserModel ====================

    public function testLoadConfigWithUserModel(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } }
          },
          "impl": {
            "userModel": "user",
            "models": {
              "user": {
                "class": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\ValidUser",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertSame("user", $config->userModel);
    }

    public function testUserModelWithoutInterfaceThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } }
          },
          "impl": {
            "userModel": "user",
            "models": {
              "user": {
                "class": "UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\InvalidUser",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "User class 'UniMethod\Frame\Tests\Unit\Config\Stub\InvalidUser' must implement UserInterface",
        );

        $this->loader->loadFromString($json);
    }

    public function testLoadConfigWithoutUserModel(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } }
          },
          "impl": {
            "models": { "user": { "class": "App\\User", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->userModel);
    }

    public function testLoadConfigWithNullUserModel(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } }
          },
          "impl": {
            "userModel": null,
            "models": { "user": { "class": "App\\User", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);
        $this->assertNull($config->userModel);
    }

    public function testUserModelNotInModelsThrowsException(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": { "user": { "attributes": { "id": { "type": "int" } } } }
          },
          "impl": {
            "userModel": "admin",
            "models": { "user": { "class": "App\\User", "attributes": { "id": { "getter": "getId" } } } }
          }
        }
        JSON;

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "UserModel 'admin' does not exist in the models list",
        );

        $this->loader->loadFromString($json);
    }

    // ==================== ListActionConfig Tests ====================
    //
    // ==================== Тесты ListActionConfig ====================

    public function testLoadListActionWithoutConfig(): void
    {
        $config = $this->loader->loadFromString($this->postJson('"list": {}'));

        $action = $config->actions[0];
        $this->assertSame(ActionType::List, $action->type);
        $this->assertNull($action->config);
    }

    public function testLoadListActionWithNullConfig(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson('"list": { "config": null }'),
        );

        $action = $config->actions[0];
        $this->assertNull($action->config);
    }

    public function testLoadListActionWithPagination(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": 100, "defaultSize": 20 } } }',
            ),
        );

        $action = $config->actions[0];
        $this->assertNotNull($action->config);
        $this->assertNotNull($action->config->pagination);
        $this->assertSame(100, $action->config->pagination->maxSize);
        $this->assertSame(20, $action->config->pagination->defaultSize);
    }

    public function testLoadListActionWithPaginationNullMaxSize(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": null, "defaultSize": 50 } } }',
            ),
        );

        $pagination = $config->actions[0]->config->pagination;
        $this->assertNull($pagination->maxSize);
        $this->assertSame(50, $pagination->defaultSize);
    }

    public function testLoadListActionWithDefaultPagination(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson('"list": { "config": { "pagination": {} } }'),
        );

        $pagination = $config->actions[0]->config->pagination;
        $this->assertSame(
            PaginationRule::DEFAULT_MAX_SIZE,
            $pagination->maxSize,
        );
        $this->assertSame(20, $pagination->defaultSize);
    }

    public function testLoadListActionWithoutMaxSizeMatchesConstructorDefault(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "defaultSize": 50 } } }',
            ),
        );

        $pagination = $config->actions[0]->config->pagination;
        $default = new PaginationRule();
        $this->assertSame($default->maxSize, $pagination->maxSize);
        $this->assertSame(50, $pagination->defaultSize);
    }

    public function testLoadListActionWithExplicitNullMaxSizeRemovesLimit(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": null } } }',
            ),
        );

        $pagination = $config->actions[0]->config->pagination;
        $this->assertNull($pagination->maxSize);
    }

    public function testLoadListActionWithSortRules(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "sort": [ { "path": "createdAt", "defaultDirection": "desc", "comment": ' .
                '"Сортировка по дате создания" }, { "path": "title", "defaultDirection": "asc" } ] } }',
            ),
        );

        $sortRules = $config->actions[0]->config->sort;
        $this->assertCount(2, $sortRules);

        $this->assertSame("createdAt", $sortRules[0]->path);
        $this->assertSame(SortDirection::Desc, $sortRules[0]->defaultDirection);
        $this->assertSame(
            "Сортировка по дате создания",
            $sortRules[0]->comment,
        );

        $this->assertSame("title", $sortRules[1]->path);
        $this->assertSame(SortDirection::Asc, $sortRules[1]->defaultDirection);
    }

    public function testLoadListActionWithFilterRules(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "title", "operators": ["equal", "like"] }, { "path": ' .
                '"status", "operators": ["equal", "in"] } ] } }',
            ),
        );

        $filters = $config->actions[0]->config->filters;
        $this->assertCount(2, $filters);

        $this->assertSame("title", $filters[0]->path);
        $this->assertEquals(
            [FilterOperator::Equal, FilterOperator::Like],
            $filters[0]->operators,
        );

        $this->assertSame("status", $filters[1]->path);
        $this->assertEquals(
            [FilterOperator::Equal, FilterOperator::In],
            $filters[1]->operators,
        );
    }

    public function testLoadCompleteListActionConfig(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": 100, "defaultSize": 20 }, "sort": [ { "path": ' .
                '"createdAt", "defaultDirection": "desc" } ], "filters": [ { "path": "status", "operators": ' .
                '["equal", "in"], "typeSpec": "string" } ] } }',
                '"list": { "config": { "class": "PostListFilter" } }',
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertSame("PostListFilter", $listConfig->class);
        $this->assertNotNull($listConfig->pagination);
        $this->assertCount(1, $listConfig->sort);
        $this->assertCount(1, $listConfig->filters);
    }

    public function testMissingSortPathThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "sort": [ { "defaultDirection": "asc" } ] } }',
            ),
        );
    }

    public function testMissingFilterPathThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "operators": ["equal"] } ] } }',
            ),
        );
    }

    public function testMissingFilterOperatorsThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[post][list].config.filters[0].operators' is required and must be an array",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status" } ] } }',
            ),
        );
    }

    public function testInvalidPaginationMaxSizeThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[post][list].config.pagination.maxSize' must be an integer or null",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": "invalid" } } }',
            ),
        );
    }

    public function testInvalidPaginationDefaultSizeThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[post][list].config.pagination.defaultSize' must be an integer",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "defaultSize": "invalid" } } }',
            ),
        );
    }

    // ==================== Repository Tests ====================
    //
    // ==================== Тесты Repository ====================

    public function testLoadListActionWithValidRepository(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": {} }',
                '"list": { "config": { "repository": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\ValidRepository" } }',
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertSame(ValidRepository::class, $listConfig->repository);
    }

    public function testLoadListActionWithNullRepository(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": {} }',
                '"list": { "config": { "repository": null } }',
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertNull($listConfig->repository);
    }

    public function testLoadListActionWithoutRepository(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": 100 } } }',
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertNull($listConfig->repository);
    }

    public function testRepositoryClassNotExistsThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Repository class 'App\\NonExistentRepository' specified in 'impl.actions[post][list].config' does not " .
            "exist",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": {}',
                '"list": { "config": { "repository": "App\\\\NonExistentRepository" } }',
            ),
        );
    }

    public function testRepositoryWithoutInterfaceThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Repository class 'UniMethod\\Frame\\Tests\\Unit\\Config\\Stub\\InvalidRepository' specified in " .
            "'impl.actions[post][list].config' must implement EntityCollectionInterface",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": {}',
                '"list": { "config": { "repository": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\InvalidRepository" } }',
            ),
        );
    }

    public function testLoadCompleteListActionWithRepository(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "pagination": { "maxSize": 100, "defaultSize": 20 }, "sort": [ { "path": ' .
                '"createdAt", "defaultDirection": "desc" } ], "filters": [ { "path": "status", "operators": ' .
                '["equal", "in"], "typeSpec": "string" } ] } }',
                '"list": { "config": { "class": "PostListFilter", "repository": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\ValidRepository" } }',
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertSame("PostListFilter", $listConfig->class);
        $this->assertSame(ValidRepository::class, $listConfig->repository);
        $this->assertNotNull($listConfig->pagination);
        $this->assertCount(1, $listConfig->sort);
        $this->assertCount(1, $listConfig->filters);
    }

    // ==================== FilterOperatorConfig Tests ====================
    //
    // ==================== Тесты FilterOperatorConfig ====================

    public function testFilterOperatorExtendedFormatWithAuthRequired(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status", "operators": [ { "operator": "equal", ' .
                '"access": { "authRequired": true } } ] } ] } }',
            ),
        );

        $operators = $config->actions[0]->config->filters[0]->operators;
        $this->assertCount(1, $operators);
        $this->assertInstanceOf(FilterOperatorConfig::class, $operators[0]);
        $this->assertSame(FilterOperator::Equal, $operators[0]->operator);
        $this->assertNotNull($operators[0]->access);
        $this->assertTrue($operators[0]->access->authRequired);
        $this->assertNull($operators[0]->access->roles);
    }

    public function testFilterOperatorExtendedFormatWithRoles(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status", "operators": [ { "operator": "in", "access": ' .
                '{ "authRequired": true, "roles": ["ROLE_ADMIN", "ROLE_MANAGER"] } } ] } ] } }',
            ),
        );

        $operators = $config->actions[0]->config->filters[0]->operators;
        $this->assertCount(1, $operators);
        $this->assertInstanceOf(FilterOperatorConfig::class, $operators[0]);
        $this->assertSame(FilterOperator::In, $operators[0]->operator);
        $this->assertNotNull($operators[0]->access);
        $this->assertTrue($operators[0]->access->authRequired);
        $this->assertSame(
            ["ROLE_ADMIN", "ROLE_MANAGER"],
            $operators[0]->access->roles,
        );
    }

    public function testFilterOperatorExtendedFormatWithNullAccess(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status", "operators": [ { "operator": "like" } ] } ] ' .
                '} }',
            ),
        );

        $operators = $config->actions[0]->config->filters[0]->operators;
        $this->assertCount(1, $operators);
        $this->assertInstanceOf(FilterOperatorConfig::class, $operators[0]);
        $this->assertSame(FilterOperator::Like, $operators[0]->operator);
        $this->assertNull($operators[0]->access);
    }

    public function testFilterOperatorMixedShortAndExtendedFormat(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status", "operators": [ "equal", { "operator": "in", ' .
                '"access": { "authRequired": true, "roles": ["ROLE_ADMIN"] } } ] } ] } }',
            ),
        );

        $operators = $config->actions[0]->config->filters[0]->operators;
        $this->assertCount(2, $operators);

        $this->assertInstanceOf(FilterOperator::class, $operators[0]);
        $this->assertSame(FilterOperator::Equal, $operators[0]);

        $this->assertInstanceOf(FilterOperatorConfig::class, $operators[1]);
        $this->assertSame(FilterOperator::In, $operators[1]->operator);
        $this->assertNotNull($operators[1]->access);
        $this->assertSame(["ROLE_ADMIN"], $operators[1]->access->roles);
    }

    public function testFilterOperatorExtendedFormatMissingOperatorKeyThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'operator' in 'schema.actions[post][list].config.filters[0].operators[0]' must be a non-empty " .
            "string",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status", "operators": [ { "access": { "authRequired": ' .
                'true } } ] } ] } }',
            ),
        );
    }

    public function testFilterOperatorExtendedFormatInvalidOperatorValueThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("Invalid filter operator 'invalid_op'");

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "status", "operators": [ { "operator": "invalid_op" } ' .
                '] } ] } }',
            ),
        );
    }

    public function testFilterRuleWithTypeSpecLoaded(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "externalId", "operators": ["equal"], "typeSpec": ' .
                '"int" } ] } }',
            ),
        );

        $filter = $config->actions[0]->config->filters[0];
        $this->assertSame("externalId", $filter->path);
        $this->assertNotNull($filter->typeSpec);
        $this->assertSame(ValueKind::Int, $filter->typeSpec->kind);
        $this->assertFalse($filter->typeSpec->nullable);
        $this->assertFalse($filter->typeSpec->isList);
    }

    public function testFilterRuleWithNullableTypeSpec(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "externalId", "operators": ["equal"], "typeSpec": ' .
                '"string|null" } ] } }',
            ),
        );

        $filter = $config->actions[0]->config->filters[0];
        $this->assertNotNull($filter->typeSpec);
        $this->assertSame(ValueKind::String, $filter->typeSpec->kind);
        $this->assertTrue($filter->typeSpec->nullable);
        $this->assertFalse($filter->typeSpec->isList);
    }

    public function testFilterRuleWithListTypeSpec(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "tags", "operators": ["in"], "typeSpec": "string[]" } ' .
                '] } }',
            ),
        );

        $filter = $config->actions[0]->config->filters[0];
        $this->assertNotNull($filter->typeSpec);
        $this->assertSame(ValueKind::String, $filter->typeSpec->kind);
        $this->assertFalse($filter->typeSpec->nullable);
        $this->assertTrue($filter->typeSpec->isList);
    }

    public function testFilterRuleWithoutTypeSpecIsNull(): void
    {
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "title", "operators": ["equal"] } ] } }',
            ),
        );

        $filter = $config->actions[0]->config->filters[0];
        $this->assertNull($filter->typeSpec);
    }

    public function testFilterRuleWithUnknownTypeSpecThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[post][list].config.filters[0].typeSpec' contains unknown type:",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "externalId", "operators": ["equal"], "typeSpec": ' .
                '"foobar" } ] } }',
            ),
        );
    }

    public function testFilterRuleWithNonStringTypeSpecThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Field 'schema.actions[post][list].config.filters[0].typeSpec' must be a string",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filters": [ { "path": "externalId", "operators": ["equal"], "typeSpec": 123 ' .
                '} ] } }',
            ),
        );
    }
}
