<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Config, Model, Relation};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
    PaginationRule,
};
use UniMethod\Frame\Mapping\ListQuery\Exception\{
    InvalidListQueryException,
    ListActionMisconfiguredException,
};
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Type\{TypeSpec, TypeSpecParser};

/**
 * Tests of the new mapFilter contract: the config is the single source of truth.
 * A field is applied only if the path is declared in config->filters AND the operator
 * is in the rule's operators; everything else — InvalidListQueryException (400).
 * A declared path that does not resolve in the schema and has no typeSpec —
 * ListActionMisconfiguredException (500).
 *
 * Тесты нового контракта mapFilter: конфиг — единственный источник истины.
 * Поле применяется только если путь заявлен в config->filters И оператор
 * входит в operators правила; всё прочее — InvalidListQueryException (400).
 * Заявленный, но нерезолвящийся в схеме путь без typeSpec —
 * ListActionMisconfiguredException (500).
 */
final class MappingToListActionFilterWhitelistTest extends TestCase
{
    private MappingToListAction $mapper;

    private TypeSpecParser $typeParser;

    protected function setUp(): void
    {
        $this->typeParser = new TypeSpecParser();
        $this->mapper = new MappingToListAction($this->typeParser);
        $this->createTestClasses();
    }

    /**
     * Stub classes are created via eval(), so static analysis does not
     * see them: the name is built dynamically and marked as class-string.
     *
     * Стаб-классы создаются через eval(), поэтому статический анализ их не
     * видит: имя собирается динамически и помечается как class-string.
     *
     * @return class-string
     */
    private function stubClass(string $shortName): string
    {
        /** @var class-string $class */
        $class = "UniMethod\Frame\Tests\Unit\Mapping\Stub\\" . $shortName;

        return $class;
    }

    private function createTestClasses(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlProductRepository",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class WlProductRepository implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
                    public function list(
                        object|null $filter = null,
                        object|null $sort = null,
                        object|null $pagination = null,
                        array $included = [],
                    ): array {}
                    public function countForList(object|null $filter = null): int {}
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlProductListRequest",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class WlProductListRequest {
                    public ?WlProductFilter $filter = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlProductFilter",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class WlProductFilter {
                    public ?WlProductEqual $equal = null;
                    public ?WlProductIn $in = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlProductEqual",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class WlProductEqual {
                    public ?string $name = null;
                    public ?int $relBrandRelId = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlProductIn",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class WlProductIn {
                    public ?array $relBrandRelId = null;
                }
            ');
        }
    }

    /**
     * @param FilterRule[] $filterRules
     * @param bool $withBrandRelation remove the relation so that the brand.id path does not resolve
     */
    private function setupConfig(
        array $filterRules,
        bool $withBrandRelation = true,
    ): void {
        $brand = new Model(
            class: "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlBrand",
            type: "wlBrand",
            attributes: [
                new Attribute(name: "id", type: "int"),
                new Attribute(name: "title", type: "string"),
            ],
            relations: [],
        );

        $product = new Model(
            class: "UniMethod\Frame\Tests\Unit\Mapping\Stub\WlProduct",
            type: "wlProduct",
            attributes: [
                new Attribute(name: "id", type: "int"),
                new Attribute(name: "name", type: "string"),
            ],
            relations: $withBrandRelation
                ? [
                    new Relation(
                        name: "brand",
                        type: "manyToOne",
                        targetModel: "wlBrand",
                    ),
                ]
                : [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "wlProduct",
            config: new ListActionConfig(
                class: $this->stubClass("WlProductListRequest"),
                repository: $this->stubClass("WlProductRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: $filterRules,
            ),
        );

        $this->mapper->setConfig(
            new Config(models: [$product, $brand], actions: [$action]),
        );
    }

    // ==================== Applying declared fields ====================
    //
    // ==================== Применение заявленных полей ====================

    public function testDeclaredFieldWithAllowedOperatorIsApplied(): void
    {
        // typeSpec is not set: the int type is taken from the schema through the chain of relations
        //
        // typeSpec не задан: тип int берётся из схемы через цепочку реляций
        $this->setupConfig([
            new FilterRule(
                path: "brand.id",
                operators: [FilterOperator::In, FilterOperator::Equal],
            ),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["in" => ["brand.id" => ["2", "3"]]]],
            "wlProduct",
        );

        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->in);
        $this->assertSame([2, 3], $result->filter->in->relBrandRelId);
    }

    public function testNestedFormOfDeclaredPathIsApplied(): void
    {
        $this->setupConfig([
            new FilterRule(path: "brand.id", operators: [FilterOperator::Equal]),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["brand" => ["id" => "2"]]]],
            "wlProduct",
        );

        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->equal);
        $this->assertSame(2, $result->filter->equal->relBrandRelId);
    }

    public function testExtendedOperatorFormIsAccepted(): void
    {
        // An operator in the FilterOperatorConfig form is equivalent to a bare enum
        //
        // Оператор в форме FilterOperatorConfig равноправен голому enum
        $this->setupConfig([
            new FilterRule(
                path: "brand.id",
                operators: [new FilterOperatorConfig(FilterOperator::In)],
            ),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["in" => ["brand.id" => ["7"]]]],
            "wlProduct",
        );

        $this->assertSame([7], $result->filter->in->relBrandRelId);
    }

    public function testTypeSpecFromRuleOverridesSchema(): void
    {
        // In the schema brand.id is int, the rule imposes string
        //
        // В схеме brand.id — int, правило навязывает string
        $this->setupConfig([
            new FilterRule(
                path: "brand.id",
                operators: [FilterOperator::In],
                typeSpec: $this->typeParser->parse("string"),
            ),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["in" => ["brand.id" => ["2"]]]],
            "wlProduct",
        );

        $this->assertSame(["2"], $result->filter->in->relBrandRelId);
    }

    // ==================== Rejecting undeclared ====================
    //
    // ==================== Отклонение незаявленного ====================

    public function testUndeclaredFieldIsRejected(): void
    {
        // name is in the schema attributes and in the DTO but is not declared in filters —
        // the DTO structure is no longer the source of truth
        //
        // name есть в атрибутах схемы и в DTO, но не заявлен в filters —
        // структура DTO больше не является источником истины
        $this->setupConfig([
            new FilterRule(path: "brand.id", operators: [FilterOperator::Equal]),
        ]);

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Unknown filter field 'name'");

        $this->mapper->map(
            ["filter" => ["equal" => ["name" => "x"]]],
            "wlProduct",
        );
    }

    public function testUndeclaredDottedPathIsRejected(): void
    {
        // The scenario from the bug report: filter[in][brand.id][0]=2 without a rule
        // used to be silently dropped and the request was executed without the filter
        //
        // Сценарий из багрепорта: filter[in][brand.id][0]=2 без правила
        // раньше молча выбрасывался и запрос выполнялся без фильтра
        $this->setupConfig([
            new FilterRule(path: "name", operators: [FilterOperator::Equal]),
        ]);

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Unknown filter field 'brand.id'");

        $this->mapper->map(
            ["filter" => ["in" => ["brand.id" => ["2"]]]],
            "wlProduct",
        );
    }

    public function testOperatorOutsideRuleOperatorsIsRejected(): void
    {
        // The operators restriction is no longer fictitious
        //
        // Ограничение operators больше не фиктивно
        $this->setupConfig([
            new FilterRule(path: "brand.id", operators: [FilterOperator::Equal]),
        ]);

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage(
            "Operator 'in' is not allowed for filter field 'brand.id'",
        );

        $this->mapper->map(
            ["filter" => ["in" => ["brand.id" => ["2"]]]],
            "wlProduct",
        );
    }

    public function testUnknownOperatorKeyIsRejected(): void
    {
        $this->setupConfig([
            new FilterRule(path: "name", operators: [FilterOperator::Equal]),
        ]);

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Unknown filter operator 'between'");

        $this->mapper->map(
            ["filter" => ["between" => ["name" => "x"]]],
            "wlProduct",
        );
    }

    // ==================== Misconfiguration and drift ====================
    //
    // ==================== Мисконфигурация и рассинхрон ====================

    public function testDeclaredUnresolvablePathWithoutTypeSpecIsMisconfiguration(): void
    {
        // There is no brand relation in the schema, typeSpec is not set, but the property
        // relBrandRelId exists in the DTO: this is not a client error (400)
        // but a misconfiguration (500)
        //
        // Реляции brand в схеме нет, typeSpec не задан, но свойство
        // relBrandRelId в DTO существует: это не клиентская ошибка (400),
        // а мисконфигурация (500)
        $this->setupConfig(
            [
                new FilterRule(
                    path: "brand.id",
                    operators: [FilterOperator::Equal],
                ),
            ],
            withBrandRelation: false,
        );

        $this->expectException(ListActionMisconfiguredException::class);
        $this->expectExceptionMessage(
            "Filter rule 'brand.id' of model 'wlProduct' does not resolve to a schema attribute and has no typeSpec",
        );

        $this->mapper->map(
            ["filter" => ["equal" => ["brand" => ["id" => "1"]]]],
            "wlProduct",
        );
    }

    public function testDeclaredUnresolvablePathWithTypeSpecIsApplied(): void
    {
        // The same case, but typeSpec is set — the only working way
        // for dotted paths without a relation in the schema
        //
        // Тот же случай, но typeSpec задан — единственный рабочий путь
        // для dotted-путей без реляции в схеме
        $this->setupConfig(
            [
                new FilterRule(
                    path: "brand.id",
                    operators: [FilterOperator::Equal],
                    typeSpec: $this->typeParser->parse("int"),
                ),
            ],
            withBrandRelation: false,
        );

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["brand" => ["id" => "5"]]]],
            "wlProduct",
        );

        $this->assertSame(5, $result->filter->equal->relBrandRelId);
    }

    public function testDeclaredPathWithoutDtoPropertyIsSilentlySkipped(): void
    {
        // The id path is declared and resolves, but there is no id property in WlProductEqual:
        // a config↔DTO mismatch — the responsibility of bootstrap validation
        // (ConfigValidator::validateListFilters), at runtime — a silent skip
        //
        // Путь id заявлен и резолвится, но свойства id в WlProductEqual нет:
        // рассинхрон конфиг↔DTO — зона ответственности bootstrap-валидации
        // (ConfigValidator::validateListFilters), в рантайме — молчаливый пропуск
        $this->setupConfig([
            new FilterRule(path: "id", operators: [FilterOperator::Equal]),
            new FilterRule(path: "name", operators: [FilterOperator::Equal]),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["id" => "5", "name" => "x"]]],
            "wlProduct",
        );

        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->equal);
        $this->assertSame("x", $result->filter->equal->name);
        $this->assertFalse(property_exists($result->filter->equal, "id"));
    }

    public function testEmptyValueArrayIsIgnoredBeforeWhitelist(): void
    {
        // An empty nested structure carries no data — it is ignored before
        // the whitelist, even if the path is not declared
        //
        // Пустая вложенная структура данных не несёт — игнорируется до
        // вайтлиста, даже если путь не заявлен
        $this->setupConfig([
            new FilterRule(path: "name", operators: [FilterOperator::Equal]),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["ghost" => [], "name" => "x"]]],
            "wlProduct",
        );

        $this->assertNotNull($result->filter);
        $this->assertSame("x", $result->filter->equal->name);
    }
}
