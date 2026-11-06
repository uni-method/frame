<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Config, JsonConfigLoader};
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * ConfigValidator::validateListRules — consistency of pagination / sort /
 * filters.defaultValue. The framework does not apply these rules, so the check at
 * bootstrap is the only guarantee the consumer gets.
 *
 * ConfigValidator::validateListRules — согласованность pagination / sort /
 * filters.defaultValue. Фреймворк эти правила не применяет, поэтому проверка на
 * bootstrap — единственная гарантия, которую получает потребитель.
 */
final class ConfigValidatorListRulesTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    // ── pagination ──────────────────────────────────────────────────────────
    //
    // ── пагинация ──────────────────────────────────────────────────────────

    #[DataProvider("provideInvalidPagination")]
    public function testInvalidPaginationIsRejected(string $pagination, string $message): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage($message);

        $this->load(sprintf('"pagination": %s', $pagination));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidPagination(): iterable
    {
        yield "maxSize = 0" => ['{ "maxSize": 0 }', "pagination.maxSize' must be a positive integer or null"];
        yield "maxSize < 0" => ['{ "maxSize": -5 }', "pagination.maxSize' must be a positive integer or null"];
        yield "defaultSize = 0" => ['{ "defaultSize": 0 }', "pagination.defaultSize' must be a positive integer"];
        yield "defaultSize > maxSize" => [
            '{ "maxSize": 10, "defaultSize": 50 }',
            "pagination.defaultSize' (50) must not exceed maxSize (10)",
        ];
    }

    public function testPaginationWithoutLimitAcceptsAnyDefaultSize(): void
    {
        $config = $this->load('"pagination": { "maxSize": null, "defaultSize": 500 }');

        self::assertSame(500, $config->actions[0]->config->pagination->defaultSize);
    }

    // ── sort ────────────────────────────────────────────────────────────────
    //
    // ── сортировка ──────────────────────────────────────────────────────────

    #[DataProvider("provideInvalidSort")]
    public function testInvalidSortIsRejected(string $sort, string $message): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage($message);

        $this->load(sprintf('"sort": %s', $sort));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidSort(): iterable
    {
        yield "путь не резолвится" => [
            '[ { "path": "missing" } ]',
            "Sort path 'missing' in schema.actions[item][list].config.sort[0] does not resolve to an attribute of " .
            "model 'item'",
        ];
        yield "путь через несуществующую связь" => [
            '[ { "path": "owner.name" } ]',
            "Sort path 'owner.name'",
        ];
        yield "дубликат пути" => [
            '[ { "path": "name" }, { "path": "name", "defaultDirection": "desc" } ]',
            "Duplicate sort rule for path 'name' in schema.actions[item][list].config.sort[1]",
        ];
        yield "required + defaultValue" => [
            '[ { "path": "name", "required": true, "defaultValue": { "path": "name", "direction": "asc" } } ]',
            "cannot be both required and have a defaultValue",
        ];
        yield "defaultDirection + defaultValue" => [
            '[ { "path": "name", "defaultDirection": "asc", "defaultValue": { "path": "name", "direction": "asc" } } ]',
            "must use either defaultDirection or defaultValue, not both",
        ];
        yield "defaultValue.path не резолвится" => [
            '[ { "path": "name", "defaultValue": { "path": "missing", "direction": "asc" } } ]',
            "Sort defaultValue path 'missing'",
        ];
    }

    public function testSortPathThroughRelationIsAccepted(): void
    {
        $config = $this->load(
            '"sort": [ { "path": "author.name", "defaultDirection": "asc" }, { "path": "createdAt" } ]',
        );

        self::assertCount(2, $config->actions[0]->config->sort);
    }

    // ── filters.defaultValue ────────────────────────────────────────────────

    #[DataProvider("provideInvalidFilterDefaults")]
    public function testInvalidFilterDefaultIsRejected(string $filters, string $message): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage($message);

        $this->load(sprintf('"filters": %s', $filters));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidFilterDefaults(): iterable
    {
        yield "дубликат пути" => [
            '[ { "path": "qty", "operators": ["equal"] }, { "path": "qty", "operators": ["in"] } ]',
            "Duplicate filter rule for path 'qty' in schema.actions[item][list].config.filters[1]",
        ];
        yield "required + defaultValue" => [
            '[ { "path": "qty", "operators": ["equal"], "required": true, "defaultValue": { "operator": "equal", ' .
            '"value": 1 } } ]',
            "cannot be both required and have a defaultValue",
        ];
        yield "оператор не из operators" => [
            '[ { "path": "qty", "operators": ["equal"], "defaultValue": { "operator": "in", "value": [1] } } ]',
            "Filter defaultValue operator 'in' in schema.actions[item][list].config.filters[0].defaultValue is not " .
            "among the rule operators (equal)",
        ];
        yield "in со скаляром" => [
            '[ { "path": "qty", "operators": ["in"], "defaultValue": { "operator": "in", "value": 1 } } ]',
            "for operator 'in' in schema.actions[item][list].config.filters[0].defaultValue must be a list",
        ];
        yield "range не из двух" => [
            '[ { "path": "qty", "operators": ["range"], "defaultValue": { "operator": "range", "value": [1, 2, 3] } ' .
            '} ]',
            "must be a list of exactly two items [from, to]",
        ];
        yield "equal со списком" => [
            '[ { "path": "qty", "operators": ["equal"], "defaultValue": { "operator": "equal", "value": [1] } } ]',
            "for operator 'equal' in schema.actions[item][list].config.filters[0].defaultValue must be a scalar",
        ];
        yield "тип по атрибуту: int ← 'abc'" => [
            '[ { "path": "qty", "operators": ["equal"], "defaultValue": { "operator": "equal", "value": "abc" } } ]',
            "does not match the field type",
        ];
        yield "тип по атрибуту: элемент списка in" => [
            '[ { "path": "qty", "operators": ["in"], "defaultValue": { "operator": "in", "value": [1, "x"] } } ]',
            "does not match the field type",
        ];
        yield "тип по typeSpec: datetime ← относительный формат" => [
            '[ { "path": "createdAt", "operators": ["range"], "typeSpec": "datetime", "defaultValue": { "operator": ' .
            '"range", "value": ["now", "+1 day"] } } ]',
            "does not match the field type",
        ];
    }

    public function testValidFilterDefaultsAreAccepted(): void
    {
        $config = $this->load(<<<'JSON'
            "filters": [
              { "path": "qty", "operators": ["equal", "in"], "defaultValue": { "operator": "in", "value": [1, 2] } },
              { "path": "active", "operators": ["equal"], "defaultValue": { "operator": "equal", "value": true } },
              {
                "path": "createdAt",
                "operators": ["range"],
                "defaultValue": { "operator": "range", "value": ["2026-01-01", "2026-12-31"] }
              },
              { "path": "author.name", "operators": ["like"], "defaultValue": { "operator": "like", "value": "A" } }
            ]
            JSON);

        self::assertCount(4, $config->actions[0]->config->filters);
    }

    /**
     * Without typeSpec and without a resolving attribute the type is unknown — the shape
     * is checked, the value is not (as in validateListFilters without class).
     *
     * Без typeSpec и без резолвящегося атрибута тип неизвестен — форма
     * проверяется, значение нет (как в validateListFilters без class).
     */
    public function testDefaultValueWithUnknownTypeIsNotTypeChecked(): void
    {
        $config = $this->load(
            '"filters": [ { "path": "ghost", "operators": ["equal"], "defaultValue": { "operator": "equal", "value": ' .
            '"anything" } } ]',
        );

        self::assertSame("anything", $config->actions[0]->config->filters[0]->defaultValue->value);
    }

    // ── helpers ─────────────────────────────────────────────────────────────
    //
    // ── хелперы ─────────────────────────────────────────────────────────────

    /**
     * The item model (id, name, qty:int, active:bool, createdAt:datetime, author -> author{name}).
     *
     * Модель item (id, name, qty:int, active:bool, createdAt:datetime, author -> author{name}).
     */
    private function load(string $listConfigBody): Config
    {
        $json = <<<JSON
        {
          "schema": {
            "models": {
              "item": {
                "attributes": {
                  "id": { "type": "int" },
                  "name": { "type": "string" },
                  "qty": { "type": "int" },
                  "active": { "type": "bool" },
                  "createdAt": { "type": "datetime" }
                },
                "relations": {
                  "author": { "type": "manyToOne", "targetModel": "author" }
                }
              },
              "author": {
                "attributes": {
                  "id": { "type": "int" },
                  "name": { "type": "string" }
                }
              }
            },
            "actions": {
              "item": { "list": { "config": { {$listConfigBody} } } }
            }
          },
          "impl": {
            "models": {
              "item": {
                "class": "App\\\\Item",
                "attributes": {
                  "id": { "getter": "getId" },
                  "name": { "getter": "getName" },
                  "qty": { "getter": "getQty" },
                  "active": { "getter": "isActive" },
                  "createdAt": { "getter": "getCreatedAt" }
                },
                "relations": { "author": { "getter": "getAuthor" } }
              },
              "author": {
                "class": "App\\\\Author",
                "attributes": { "id": { "getter": "getId" }, "name": { "getter": "getName" } }
              }
            }
          }
        }
        JSON;

        return $this->loader->loadFromString($json);
    }
}
