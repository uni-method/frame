<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{AccessConfig, Action, ActionType, Attribute, Config, Model, Relation};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
};
use UniMethod\Frame\Mapping\ListQuery\Exception\InvalidListQueryException;
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Tests\Unit\Stub\{Item, Writer};
use UniMethod\Frame\Type\TypeSpecParser;

/**
 * MappingToListAction::parseFilters is the only parsing of `filter[...]` in the lib:
 * the result format, the whitelist, what is skipped and what gives 400.
 *
 * MappingToListAction::parseFilters — единственный разбор `filter[...]` в либе:
 * формат результата, вайтлист, что пропускается и что даёт 400.
 */
final class MappingToListActionParseFiltersTest extends TestCase
{
    public function testReturnsOneParsedFilterPerOperatorAndPath(): void
    {
        $parsed = $this->mapper()->parseFilters(
            [
                "filter" => [
                    "equal" => ["qty" => "5", "owner" => ["id" => "7"]],
                    "in" => ["qty" => ["1", "2"]],
                ],
            ],
            "item",
        );

        self::assertCount(3, $parsed);

        [$equalQty, $equalOwnerId, $inQty] = $parsed;

        self::assertSame(FilterOperator::Equal, $equalQty->operator);
        self::assertSame("qty", $equalQty->path);
        self::assertSame("qty", $equalQty->rule->path);
        self::assertSame("5", $equalQty->value, "значение сырое, без приведения типов");

        // a nested structure collapses into a dot path
        //
        // вложенная структура схлопывается в точечный путь
        self::assertSame("owner.id", $equalOwnerId->path);
        self::assertSame("7", $equalOwnerId->value);

        // the list of values of the in operator does not collapse
        //
        // список значений оператора in не схлопывается
        self::assertSame(FilterOperator::In, $inQty->operator);
        self::assertSame(["1", "2"], $inQty->value);
    }

    public function testOperatorAccessComesFromExtendedOperatorForm(): void
    {
        [$equal, $in] = $this->mapper()->parseFilters(
            ["filter" => ["equal" => ["qty" => "1"], "in" => ["qty" => ["1"]]]],
            "item",
        );

        self::assertNull($equal->operatorAccess());
        self::assertInstanceOf(AccessConfig::class, $in->operatorAccess());
        self::assertSame(["ROLE_ADMIN"], $in->operatorAccess()->roles);
    }

    public function testEmptyAndNonArrayStructuresAreSkipped(): void
    {
        $mapper = $this->mapper();

        self::assertSame([], $mapper->parseFilters([], "item"));
        self::assertSame([], $mapper->parseFilters(["filter" => "qty"], "item"));
        self::assertSame([], $mapper->parseFilters(["filter" => ["equal" => []]], "item"));
        self::assertSame([], $mapper->parseFilters(["filter" => ["equal" => ["qty" => []]]], "item"));
    }

    public function testUnknownOperatorIsRejected(): void
    {
        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Unknown filter operator 'between'");

        $this->mapper()->parseFilters(["filter" => ["between" => ["qty" => "1"]]], "item");
    }

    public function testFieldOutsideRulesIsRejected(): void
    {
        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Unknown filter field 'ghost'");

        $this->mapper()->parseFilters(["filter" => ["equal" => ["ghost" => "1"]]], "item");
    }

    public function testOperatorOutsideRuleIsRejected(): void
    {
        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Operator 'like' is not allowed for filter field 'qty'");

        $this->mapper()->parseFilters(["filter" => ["like" => ["qty" => "1"]]], "item");
    }

    private function mapper(): MappingToListAction
    {
        $config = new Config(
            models: [
                new Model(
                    class: Item::class,
                    type: "item",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "qty", type: "int"),
                    ],
                    relations: [
                        new Relation(name: "owner", type: Relation::MANY_TO_ONE, targetModel: "writer"),
                    ],
                ),
                new Model(
                    class: Writer::class,
                    type: "writer",
                    attributes: [new Attribute(name: "id", type: "string")],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::List,
                    model: "item",
                    config: new ListActionConfig(
                        filters: [
                            new FilterRule(
                                path: "qty",
                                operators: [
                                    FilterOperator::Equal,
                                    new FilterOperatorConfig(
                                        FilterOperator::In,
                                        new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]),
                                    ),
                                ],
                            ),
                            new FilterRule(path: "owner.id", operators: [FilterOperator::Equal]),
                        ],
                    ),
                ),
            ],
        );

        return new MappingToListAction(new TypeSpecParser(), config: $config);
    }
}
