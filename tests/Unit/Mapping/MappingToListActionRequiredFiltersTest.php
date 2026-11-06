<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Config, Model};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
    PaginationRule,
};
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\RqProductEqual;
use UniMethod\Frame\Type\TypeSpecParser;

/**
 * required filters: if the operator is not passed in the query, the operator object is
 * still created (all fields null) — the validator then returns 422. An operator in
 * the extended FilterOperatorConfig form is equivalent to a bare enum.
 *
 * required-фильтры: если оператор не передан в query, объект оператора всё
 * равно создаётся (все поля null) — валидатор затем отдаёт 422. Оператор в
 * расширенной форме FilterOperatorConfig равноправен голому enum.
 */
final class MappingToListActionRequiredFiltersTest extends TestCase
{
    private const string STUB_NS = "UniMethod\Frame\Tests\Unit\Mapping\Stub";

    private MappingToListAction $mapper;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\RqProductListRequest",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class RqProduct {}

                class RqProductRepository implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
                    public function list(
                        object|null $filter = null,
                        object|null $sort = null,
                        object|null $pagination = null,
                        array $included = [],
                    ): array { return []; }
                    public function countForList(object|null $filter = null): int { return 0; }
                }

                class RqProductListRequest {
                    public ?RqProductFilter $filter = null;
                }

                class RqProductFilter {
                    public ?RqProductEqual $equal = null;
                }

                class RqProductEqual {
                    public ?string $name = null;
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->mapper = new MappingToListAction(new TypeSpecParser());
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

    /**
     * @param list<FilterRule> $filterRules
     */
    private function setupConfig(array $filterRules): void
    {
        $model = new Model(
            class: self::STUB_NS . "\\RqProduct",
            type: "rqProduct",
            attributes: [
                new Attribute(name: "id", type: "int"),
                new Attribute(name: "name", type: "string"),
            ],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "rqProduct",
            config: new ListActionConfig(
                class: $this->stubClass("RqProductListRequest"),
                repository: $this->stubClass("RqProductRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: $filterRules,
            ),
        );

        $this->mapper->setConfig(
            new Config(models: [$model], actions: [$action]),
        );
    }

    public function testRequiredFilterWithBareEnumCreatesEmptyOperatorObject(): void
    {
        $this->setupConfig([
            new FilterRule(
                path: "name",
                operators: [FilterOperator::Equal],
                required: true,
            ),
        ]);

        $result = $this->mapper->map([], "rqProduct");

        $this->assertNotNull($result->filter);
        $this->assertInstanceOf(RqProductEqual::class, $result->filter->equal);
        $this->assertNull($result->filter->equal->name);
    }

    public function testRequiredFilterWithOperatorConfigCreatesEmptyOperatorObject(): void
    {
        // The extended form is needed for access on a specific operator; the operator
        // name lives in the nested enum, not in FilterOperatorConfig itself
        //
        // Расширенная форма нужна для access на конкретный оператор; имя
        // оператора лежит во вложенном enum, а не в самом FilterOperatorConfig
        $this->setupConfig([
            new FilterRule(
                path: "name",
                operators: [
                    new FilterOperatorConfig(FilterOperator::Equal),
                ],
                required: true,
            ),
        ]);

        $result = $this->mapper->map([], "rqProduct");

        $this->assertNotNull($result->filter);
        $this->assertInstanceOf(RqProductEqual::class, $result->filter->equal);
        $this->assertNull($result->filter->equal->name);
    }

    public function testRequiredFilterWithOperatorConfigDoesNotOverwritePassedData(): void
    {
        $this->setupConfig([
            new FilterRule(
                path: "name",
                operators: [
                    new FilterOperatorConfig(FilterOperator::Equal),
                ],
                required: true,
            ),
        ]);

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["name" => "foo"]]],
            "rqProduct",
        );

        $this->assertSame("foo", $result->filter->equal->name);
    }

    public function testNonRequiredFilterWithoutDataLeavesOperatorNull(): void
    {
        $this->setupConfig([
            new FilterRule(
                path: "name",
                operators: [
                    new FilterOperatorConfig(FilterOperator::Equal),
                ],
            ),
        ]);

        $result = $this->mapper->map([], "rqProduct");

        $this->assertNull($result->filter);
    }
}
