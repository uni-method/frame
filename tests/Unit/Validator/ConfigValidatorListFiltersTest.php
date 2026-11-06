<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Model, Relation};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
    PaginationRule,
};
use UniMethod\Frame\Tests\Unit\Stub\{
    OrderListRequest,
    ProductEqual,
    ProductListRequest,
};
use UniMethod\Frame\Type\TypeSpecParser;
use UniMethod\Frame\Validator\ConfigValidator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * Tests of ConfigValidator::validateListFilters: consistency of list actions'
 * config->filters with the schema and the generated DTO classes.
 *
 * Тесты ConfigValidator::validateListFilters: согласованность config->filters
 * list-действий со схемой и сгенерированными DTO-классами.
 */
final class ConfigValidatorListFiltersTest extends TestCase
{
    private const string STUB_NS = "UniMethod\Frame\Tests\Unit\Validator\Stub";

    private ConfigValidator $validator;

    private TypeSpecParser $typeParser;

    public static function setUpBeforeClass(): void
    {
        // A consistent set: VOrderQuery/VOrderFilter/VOrderEqual/VOrderIn
        //
        // Согласованный набор: VOrderQuery/VOrderFilter/VOrderEqual/VOrderIn
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Validator\Stub\VOrderQuery")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VOrderQuery {
                    public ?VOrderFilter $filter = null;
                }
                class VOrderFilter {
                    public ?VOrderEqual $equal = null;
                    public ?VOrderIn $in = null;
                }
                class VOrderEqual {
                    public ?string $name = null;
                    public ?int $relBrandRelId = null;
                }
                class VOrderIn {
                    public ?array $relBrandRelId = null;
                }
            ');
        }

        // A set with a dead orphan property in the operator class
        //
        // Набор с мёртвым свойством orphan в классе оператора
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Validator\Stub\VDeadQuery")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VDeadQuery {
                    public ?VDeadFilter $filter = null;
                }
                class VDeadFilter {
                    public ?VDeadEqual $equal = null;
                }
                class VDeadEqual {
                    public ?string $name = null;
                    public ?string $orphan = null;
                }
            ');
        }

        // A set without a Filter class: VNoFilterQuery exists, VNoFilterFilter does not
        //
        // Набор без Filter-класса: VNoFilterQuery есть, VNoFilterFilter нет
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Validator\Stub\VNoFilterQuery",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VNoFilterQuery {
                    public ?object $filter = null;
                }
            ');
        }

        // A set without the filter property on the root class
        //
        // Набор без свойства filter у корневого класса
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Validator\Stub\VRootlessQuery",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VRootlessQuery {
                }
                class VRootlessFilter {
                    public ?VRootlessEqual $equal = null;
                }
                class VRootlessEqual {
                    public ?string $name = null;
                }
            ');
        }

        // A set for checking "the operator class exists, the property in Filter does not":
        // VRlRange exists, but VRlFilter has no range property
        //
        // Набор для проверки «класс оператора есть, свойства в Filter нет»:
        // VRlRange существует, но у VRlFilter нет свойства range
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Validator\Stub\VRlQuery")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VRlQuery {
                    public ?VRlFilter $filter = null;
                }
                class VRlFilter {
                    public ?VRlEqual $equal = null;
                }
                class VRlEqual {
                    public ?string $name = null;
                }
                class VRlRange {
                    public ?string $name = null;
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
        $this->typeParser = new TypeSpecParser();
    }

    /**
     * @param FilterRule[] $filterRules
     * @param string $origin base name of the stub class set (VOrder, VDead, ...)
     * @param bool $withBrandRelation remove the relation so that brand.id does not resolve
     * @return array{0: Model[], 1: Action[]}
     */
    private function makeConfig(
        array $filterRules,
        string $origin = "VOrder",
        bool $withBrandRelation = true,
        ?string $rootClass = null,
    ): array {
        $brand = new Model(
            class: self::STUB_NS . "\\VBrand",
            type: "vBrand",
            attributes: [new Attribute(name: "id", type: "int")],
            relations: [],
        );

        $order = new Model(
            class: self::STUB_NS . "\\" . $origin,
            type: "vOrder",
            attributes: [
                new Attribute(name: "id", type: "int"),
                new Attribute(name: "name", type: "string"),
            ],
            relations: $withBrandRelation
                ? [
                    new Relation(
                        name: "brand",
                        type: "manyToOne",
                        targetModel: "vBrand",
                    ),
                ]
                : [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "vOrder",
            config: new ListActionConfig(
                class: $this->stubClass($rootClass ?? $origin . "Query"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: $filterRules,
            ),
        );

        return [[$order, $brand], [$action]];
    }

    /**
     * @return list<FilterRule>
     */
    private function consistentRules(): array
    {
        return [
            new FilterRule(path: "name", operators: [FilterOperator::Equal]),
            new FilterRule(
                path: "brand.id",
                operators: [
                    new FilterOperatorConfig(FilterOperator::In),
                    FilterOperator::Equal,
                ],
            ),
        ];
    }

    // ==================== Consistent config ====================
    //
    // ==================== Согласованный конфиг ====================

    public function testConsistentConfigPasses(): void
    {
        [$models, $actions] = $this->makeConfig($this->consistentRules());

        $this->validator->validateListFilters($models, $actions);

        $this->addToAssertionCount(1);
    }

    public function testNullClassSkipsValidation(): void
    {
        // class === null: the mapper will return null, there is nothing to validate
        //
        // class === null: маппер вернёт null, валидировать нечего
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "ghost.id", operators: [])],
            rootClass: "irrelevant",
        );
        $actions = [
            new Action(
                type: ActionType::List,
                model: "vOrder",
                config: new ListActionConfig(
                    pagination: new PaginationRule(
                        maxSize: 100,
                        defaultSize: 20,
                    ),
                    sort: [],
                    filters: [new FilterRule(path: "ghost.id", operators: [])],
                ),
            ),
        ];

        $this->validator->validateListFilters($models, $actions);

        $this->addToAssertionCount(1);
    }

    // ==================== Checks without generated classes ====================
    //
    // ==================== Проверки без сгенерированных классов ====================

    public function testRuleWithoutOperatorsThrows(): void
    {
        [$models, $actions] = $this->makeConfig([
            new FilterRule(path: "name", operators: []),
        ]);

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'name' of model 'vOrder' must declare at least one operator",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testUnresolvablePathWithoutTypeSpecThrows(): void
    {
        [$models, $actions] = $this->makeConfig(
            [
                new FilterRule(
                    path: "brand.id",
                    operators: [FilterOperator::Equal],
                ),
            ],
            withBrandRelation: false,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'brand.id' of model 'vOrder' does not resolve to a schema attribute and has no typeSpec",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testUnresolvablePathWithTypeSpecPasses(): void
    {
        // typeSpec replaces resolution by the schema; the relBrandRelId property exists in the DTO
        //
        // typeSpec заменяет резолв по схеме; свойство relBrandRelId в DTO есть
        [$models, $actions] = $this->makeConfig(
            [
                new FilterRule(path: "name", operators: [FilterOperator::Equal]),
                new FilterRule(
                    path: "brand.id",
                    operators: [FilterOperator::Equal, FilterOperator::In],
                    typeSpec: $this->typeParser->parse("int"),
                ),
            ],
            withBrandRelation: false,
        );

        $this->validator->validateListFilters($models, $actions);

        $this->addToAssertionCount(1);
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
        $class = self::STUB_NS . "\\" . $shortName;

        return $class;
    }

    public function testUnknownModelThrows(): void
    {
        [$models] = $this->makeConfig([]);
        $actions = [
            new Action(
                type: ActionType::List,
                model: "ghostModel",
                config: new ListActionConfig(
                    class: $this->stubClass("VOrderQuery"),
                    pagination: new PaginationRule(
                        maxSize: 100,
                        defaultSize: 20,
                    ),
                    sort: [],
                    filters: [],
                ),
            ),
        ];

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "List action refers to unknown model 'ghostModel'",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    // ==================== Classes are not generated ====================
    //
    // ==================== Классы не сгенерированы ====================

    public function testNotGeneratedRootClassSkipsReflexiveChecks(): void
    {
        // Rules resolve by the schema — the config is valid even though there are no DTO classes
        // (the class generator loads the config itself)
        //
        // Правила резолвятся по схеме — конфиг валиден, хотя DTO-классов нет
        // (генератор классов сам загружает конфиг)
        [$models, $actions] = $this->makeConfig(
            $this->consistentRules(),
            rootClass: self::STUB_NS . "\\VNotGeneratedQuery",
        );

        $this->validator->validateListFilters($models, $actions);

        $this->addToAssertionCount(1);
    }

    public function testNotGeneratedRootClassStillValidatesPathResolution(): void
    {
        // The path resolution check does not depend on classes and works before generation
        //
        // Проверка резолва пути не зависит от классов и работает до генерации
        [$models, $actions] = $this->makeConfig(
            [
                new FilterRule(
                    path: "ghost.id",
                    operators: [FilterOperator::Equal],
                ),
            ],
            rootClass: self::STUB_NS . "\\VNotGeneratedQuery",
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'ghost.id' of model 'vOrder' does not resolve to a schema attribute and has no typeSpec",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    // ==================== Config ↔ generated classes drift ====================
    //
    // ==================== Рассинхрон конфиг ↔ сгенерированные классы ====================

    public function testDeclaredFiltersWithoutFilterClassThrows(): void
    {
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "name", operators: [FilterOperator::Equal])],
            origin: "VNoFilter",
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Model 'vOrder' declares filter rules but filter class '" .
                self::STUB_NS .
                "\\VNoFilterFilter' is missing or root class '" .
                self::STUB_NS .
                "\\VNoFilterQuery' has no 'filter' property",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testRootClassWithoutFilterPropertyThrows(): void
    {
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "name", operators: [FilterOperator::Equal])],
            origin: "VRootless",
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("has no 'filter' property");

        $this->validator->validateListFilters($models, $actions);
    }

    public function testOperatorWithoutGeneratedClassThrows(): void
    {
        // VOrderLike is not generated
        //
        // VOrderLike не сгенерирован
        [$models, $actions] = $this->makeConfig([
            new FilterRule(path: "name", operators: [FilterOperator::Like]),
            new FilterRule(
                path: "brand.id",
                operators: [FilterOperator::Equal, FilterOperator::In],
            ),
        ]);

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'name' of model 'vOrder' declares operator 'like' but class '" .
                self::STUB_NS .
                "\\VOrderLike' is missing",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testFilterClassWithoutOperatorPropertyThrows(): void
    {
        // The VRlRange class exists, but VRlFilter has no range property
        //
        // Класс VRlRange существует, но у VRlFilter нет свойства range
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "name", operators: [FilterOperator::Range])],
            origin: "VRl",
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter class '" .
                self::STUB_NS .
                "\\VRlFilter' has no property 'range' for operator 'range' declared by rule 'name' of model 'vOrder'",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testRulePathWithoutOperatorClassPropertyThrows(): void
    {
        // The id path resolves, but there is no id property in VOrderEqual:
        // the config declares a non-existent capability
        //
        // Путь id резолвится, но свойства id в VOrderEqual нет:
        // конфиг декларирует несуществующую возможность
        [$models, $actions] = $this->makeConfig([
            new FilterRule(path: "name", operators: [FilterOperator::Equal]),
            new FilterRule(path: "id", operators: [FilterOperator::Equal]),
            new FilterRule(
                path: "brand.id",
                operators: [FilterOperator::Equal, FilterOperator::In],
            ),
        ]);

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'id' of model 'vOrder' has no matching property 'id' in class '" .
                self::STUB_NS .
                "\\VOrderEqual'",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testDeadOperatorClassPropertyWithoutRuleThrows(): void
    {
        // The orphan property in VDeadEqual is not covered by any rule —
        // after switching to the whitelist it is not filterable (dead code)
        //
        // Свойство orphan в VDeadEqual не покрыто ни одним правилом —
        // после перехода на вайтлист оно нефильтруемо (мёртвый код)
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "name", operators: [FilterOperator::Equal])],
            origin: "VDead",
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Property 'orphan' of class '" .
                self::STUB_NS .
                "\\VDeadEqual' has no corresponding rule in 'filters' of model 'vOrder' list action",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    // ==================== Without executing user code ====================
    //
    // ==================== Без исполнения пользовательского кода ====================

    /**
     * DTO properties are checked statically: the constructor of the operator class
     * is not called during validation.
     *
     * Свойства DTO проверяются статически: конструктор операторного класса
     * при валидации не вызывается.
     */
    public function testOperatorClassIsNotInstantiated(): void
    {
        ProductEqual::$constructed = 0;

        [$models, $actions] = $this->makeStubConfig("Product", ProductListRequest::class);

        $this->validator->validateListFilters($models, $actions);

        $this->assertSame(0, ProductEqual::$constructed);
    }

    /**
     * An operator class with required constructor arguments
     * (OrderEqual) is valid.
     *
     * Операторный класс с обязательными аргументами конструктора
     * (OrderEqual) валиден.
     */
    public function testOperatorClassWithRequiredConstructorArgsPasses(): void
    {
        [$models, $actions] = $this->makeStubConfig("Order", OrderListRequest::class);

        $this->validator->validateListFilters($models, $actions);

        $this->addToAssertionCount(1);
    }

    /**
     * A model with the class tests/Unit/Stub/{$origin} and the `name` rule (equal).
     *
     * Модель с классом tests/Unit/Stub/{$origin} и правилом `name` (equal).
     *
     * @param class-string $rootClass
     * @return array{0: Model[], 1: Action[]}
     */
    private function makeStubConfig(string $origin, string $rootClass): array
    {
        $model = new Model(
            class: "UniMethod\\Frame\\Tests\\Unit\\Stub\\" . $origin,
            type: "entry",
            attributes: [
                new Attribute(name: "id", type: "int"),
                new Attribute(name: "name", type: "string"),
            ],
        );

        $action = new Action(
            type: ActionType::List,
            model: "entry",
            config: new ListActionConfig(
                class: $rootClass,
                filters: [new FilterRule(path: "name", operators: [FilterOperator::Equal])],
            ),
        );

        return [[$model], [$action]];
    }
}
