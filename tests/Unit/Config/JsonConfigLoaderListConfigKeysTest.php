<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * Tests for the strictness of list config keys: unknown keys in the schema and
 * impl sections are rejected at load. In particular, the filters block in
 * the impl section is no longer silently ignored (a contributing factor of the bug
 * "an unknown filter field is silently ignored").
 *
 * Тесты строгости ключей list-конфига: неизвестные ключи в schema- и
 * impl-секциях отвергаются на загрузке. В частности, блок filters в
 * impl-секции больше не игнорируется молча (сопутствующий фактор бага
 * «неизвестное поле фильтра молча игнорируется»).
 */
final class JsonConfigLoaderListConfigKeysTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

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
            "models": { "post": { "attributes": { "id": { "type": "int" } } } },
            "actions": { "post": { {$schemaActionsBody} } }
          },
          "impl": {
            "models": { "post": { "class": "App\\\\Post", "attributes": { "id": { "getter": "getId" } } } }
            {$implActions}
          }
        }
        JSON;
    }

    // ==================== schema section ====================
    //
    // ==================== schema-секция ====================

    public function testUnknownKeyInSchemaListConfigThrows(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Unknown key 'filtres' in 'schema.actions[post][list].config'. Allowed keys: pagination, sort, filters",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "filtres": [ { "path": "id", "operators": ["equal"] } ] } }',
            ),
        );
    }

    public function testNonNullClassInSchemaListConfigThrows(): void
    {
        // class belongs to the impl section; only an explicit null is allowed in schema
        //
        // class принадлежит impl-секции; в schema допустим только явный null
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Key 'class' in 'schema.actions[post][list].config' must be set in the impl section",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "class": "App\\\\Query\\\\PostListQuery" } }',
            ),
        );
    }

    public function testNonNullRepositoryInSchemaListConfigThrows(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Key 'repository' in 'schema.actions[post][list].config' must be set in the impl section",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "repository": "App\\\\Repo" } }',
            ),
        );
    }

    public function testNullClassAndRepositoryInSchemaListConfigAreTolerated(): void
    {
        // Legacy format: an explicit null carries no information
        //
        // Легаси-формат: явный null информации не несёт
        $config = $this->loader->loadFromString(
            $this->postJson(
                '"list": { "config": { "class": null, "repository": null, "pagination": { "defaultSize": 30 } } }',
            ),
        );

        $listConfig = $config->actions[0]->config;
        $this->assertNotNull($listConfig);
        $this->assertNull($listConfig->class);
        $this->assertNull($listConfig->repository);
    }

    // ==================== impl section ====================
    //
    // ==================== impl-секция ====================

    public function testFiltersInImplSectionThrows(): void
    {
        // Previously the filters block in the impl section was accepted by the parser and silently
        // ignored — the rules were "lost", and the filter field degraded
        // into a silent skip
        //
        // Раньше блок filters в impl-секции принимался парсером и молча
        // игнорировался — правила «терялись», а поле фильтра деградировало
        // в тихий пропуск
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Unknown key 'filters' in 'impl.actions[post][list].config'. Allowed keys: class, repository",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": {}',
                '"list": { "config": { "class": "App\\\\Query\\\\PostListQuery", "filters": [ { "path": "id", ' .
                '"operators": ["equal"] } ] } }',
            ),
        );
    }

    public function testUnknownKeyInImplListConfigThrows(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Unknown key 'sort' in 'impl.actions[post][list].config'. Allowed keys: class, repository",
        );

        $this->loader->loadFromString(
            $this->postJson(
                '"list": {}',
                '"list": { "config": { "sort": [] } }',
            ),
        );
    }
}
