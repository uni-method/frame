<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    Relation,
};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterRule,
    ListActionConfig,
    PaginationRule,
};
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Type\TypeSpecParser;

/**
 * Tests for nested filters by relations
 *
 * Тесты для вложенных фильтров по связям
 */
final class MappingToListActionNestedFiltersTest extends TestCase
{
    private MappingToListAction $mapper;

    private Config $config;

    protected function setUp(): void
    {
        $typeParser = new TypeSpecParser();
        $this->mapper = new MappingToListAction($typeParser);
        $this->createTestClasses();
        $this->setupConfigWithRelations();
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
        $class = $shortName;

        return $class;
    }

    private function createTestClasses(): void
    {
        // Repository
        if (!class_exists("TestOneWinLinkRepository")) {
            eval('
                class TestOneWinLinkRepository implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
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

        // OneWinLinkListRequest
        if (!class_exists("TestOneWinLinkListRequest")) {
            eval('
                class TestOneWinLinkListRequest {
                    public ?TestOneWinLinkFilter $filter = null;
                    public ?TestOneWinLinkPagination $pagination = null;
                }
            ');
        }

        // OneWinLinkFilter
        if (!class_exists("TestOneWinLinkFilter")) {
            eval('
                class TestOneWinLinkFilter {
                    public ?TestOneWinLinkEqual $equal = null;
                    public ?TestOneWinLinkLike $like = null;
                    public ?TestOneWinLinkIn $in = null;
                }
            ');
        }

        // OneWinLinkEqual
        if (!class_exists("TestOneWinLinkEqual")) {
            eval('
                class TestOneWinLinkEqual {
                    // Old field for backward compatibility
                    //
                    // Старое поле для обратной совместимости
                    public ?string $ownerId = null;

                    // New field for the owner.id relation
                    //
                    // Новое поле для связи owner.id
                    public ?int $relOwnerRelId = null;

                    public ?string $originalId = null;
                }
            ');
        }

        // OneWinLinkLike
        if (!class_exists("TestOneWinLinkLike")) {
            eval('
                class TestOneWinLinkLike {
                    public ?string $name = null;

                    // For the test of the deeply nested relation owner.office.name
                    //
                    // Для теста глубоко вложенной связи owner.office.name
                    public ?string $relOwnerRelOfficeRelName = null;
                }
            ');
        }

        // OneWinLinkIn
        if (!class_exists("TestOneWinLinkIn")) {
            eval('
                class TestOneWinLinkIn {
                    public ?array $relOwnerRelId = null;
                }
            ');
        }

        // OneWinLinkPagination
        if (!class_exists("TestOneWinLinkPagination")) {
            eval('
                class TestOneWinLinkPagination {
                    public int $number = 1;
                    public int $size = 20;
                }
            ');
        }
    }

    private function setupConfigWithRelations(): void
    {
        // OneWinAccount model
        //
        // Модель OneWinAccount
        $accountModel = new Model(
            class: "TestOneWinAccount",
            type: "oneWinAccount",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "email",
                    type: "string",
                    getter: "getEmail",
                    setter: "setEmail",
                    constructorParam: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "office",
                    type: "manyToOne",
                    targetModel: "office",
                    getter: "getOffice",
                    setter: "setOffice",
                ),
            ],
        );

        // Office model
        //
        // Модель Office
        $officeModel = new Model(
            class: "TestOneWinOffice",
            type: "office",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: "getName",
                    setter: "setName",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        // OneWinLink model
        //
        // Модель OneWinLink
        $linkModel = new Model(
            class: "TestOneWinLink",
            type: "oneWinLink",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "originalId",
                    type: "string",
                    getter: "getOriginalId",
                    setter: "setOriginalId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "name",
                    type: "string",
                    getter: "getName",
                    setter: "setName",
                    constructorParam: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "owner",
                    type: "manyToOne",
                    targetModel: "oneWinAccount",
                    getter: "getOwner",
                    setter: "setOwner",
                ),
                new Relation(
                    name: "office",
                    type: "manyToOne",
                    targetModel: "office",
                    getter: "getOffice",
                    setter: "setOffice",
                ),
            ],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "oneWinLink",
            config: new ListActionConfig(
                class: $this->stubClass("TestOneWinLinkListRequest"),
                repository: $this->stubClass("TestOneWinLinkRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "owner.id",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "originalId",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "name",
                        operators: [FilterOperator::Like],
                        required: false,
                    ),
                    new FilterRule(
                        path: "owner.office.name",
                        operators: [FilterOperator::Like],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$linkModel, $accountModel, $officeModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    // ==================== Nested Filter Tests ====================
    //
    // ==================== Тесты вложенных фильтров ====================

    public function testNestedFilterStructureIsFlattened(): void
    {
        // Nested format: filter[equal][owner][id]=1
        //
        // Вложенный формат: filter[equal][owner][id]=1
        $rawQuery = [
            "filter" => [
                "equal" => [
                    "owner" => ["id" => "1"],
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check that the filter is created
        //
        // Проверяем что фильтр создан
        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->equal);

        // Check that the value ended up in the correct field
        //
        // Проверяем что значение попало в правильное поле
        $this->assertSame(1, $result->filter->equal->relOwnerRelId);
        $this->assertNull($result->filter->equal->ownerId);
    }

    public function testFlatFilterStructureStillWorks(): void
    {
        // Flat format: filter[equal][owner.id]=1
        //
        // Плоский формат: filter[equal][owner.id]=1
        $rawQuery = [
            "filter" => [
                "equal" => [
                    "owner.id" => "1",
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check that the filter is created
        //
        // Проверяем что фильтр создан
        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->equal);

        // Check that the value ended up in the correct field
        //
        // Проверяем что значение попало в правильное поле
        $this->assertSame(1, $result->filter->equal->relOwnerRelId);
    }

    public function testNestedAndLocalFiltersWorkTogether(): void
    {
        // filter[equal][owner][id]=1&filter[equal][originalId]=123&filter[like][name]=test
        $rawQuery = [
            "filter" => [
                "equal" => [
                    "owner" => ["id" => "1"],
                    "originalId" => "123",
                ],
                "like" => [
                    "name" => "test",
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check equal filters
        //
        // Проверяем equal фильтры
        $this->assertNotNull($result->filter->equal);
        $this->assertSame(1, $result->filter->equal->relOwnerRelId);
        $this->assertSame("123", $result->filter->equal->originalId);

        // Check like filters
        //
        // Проверяем like фильтры
        $this->assertNotNull($result->filter->like);
        $this->assertSame("test", $result->filter->like->name);
    }

    public function testDeepNestedRelationPath(): void
    {
        // filter[like][owner][office][name]=CompanyName
        $rawQuery = [
            "filter" => [
                "like" => [
                    "owner" => [
                        "office" => [
                            "name" => "CompanyName",
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check that the deeply nested path is processed
        //
        // Проверяем что глубоко вложенный путь обработан
        $this->assertNotNull($result->filter->like);
        $this->assertSame(
            "CompanyName",
            $result->filter->like->relOwnerRelOfficeRelName,
        );
    }

    public function testMixedNestedAndFlatFilters(): void
    {
        // A mix of nested and flat filters
        //
        // Микс вложенных и плоских фильтров
        $rawQuery = [
            "filter" => [
                "equal" => [
                    // Nested
                    //
                    // Вложенный
                    "owner" => ["id" => "1"],
                    // Flat
                    //
                    // Плоский
                    "originalId" => "123",
                ],
                "like" => [
                    // Flat
                    //
                    // Плоский
                    "name" => "test",
                    // Flat with a dot
                    //
                    // Плоский с точкой
                    "owner.office.name" => "Office",
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check all filters
        //
        // Проверяем все фильтры
        $this->assertSame(1, $result->filter->equal->relOwnerRelId);
        $this->assertSame("123", $result->filter->equal->originalId);
        $this->assertSame("test", $result->filter->like->name);
        $this->assertSame(
            "Office",
            $result->filter->like->relOwnerRelOfficeRelName,
        );
    }

    public function testArrayValuesAreNotFlattened(): void
    {
        // Create a new configuration with the In operator
        //
        // Создаем новую конфигурацию с In оператором
        $this->setupConfigWithInOperator();

        // filter[in][owner][id]=[1,2,3] - the array of values must not be flattened
        //
        // filter[in][owner][id]=[1,2,3] - массив значений не должен быть расплющен
        $rawQuery = [
            "filter" => [
                "in" => [
                    "owner" => ["id" => ["1", "2", "3"]],
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check that the array of values is kept as an array
        //
        // Проверяем что массив значений сохранился как массив
        $this->assertNotNull($result->filter->in);
        $this->assertIsArray($result->filter->in->relOwnerRelId);
        $this->assertSame([1, 2, 3], $result->filter->in->relOwnerRelId);
    }

    public function testEmptyNestedStructureIsIgnored(): void
    {
        // An empty nested object stays as ['owner' => []] after flatten
        //
        // Пустой вложенный объект после flatten остаётся как ['owner' => []]
        $rawQuery = [
            "filter" => [
                "equal" => [
                    "owner" => [],
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // The filter is created (there is the equal operator), but owner is not processed
        //
        // Фильтр создаётся (есть operator equal), но owner не обрабатывается
        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->equal);
        // Fields are null because an empty array gives no values
        //
        // Поля null т.к. пустой массив не даёт значений
        $this->assertNull($result->filter->equal->relOwnerRelId);
    }

    public function testNullValuesArePreserved(): void
    {
        // filter[equal][owner][id]=null
        $rawQuery = [
            "filter" => [
                "equal" => [
                    "owner" => ["id" => null],
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Null values must be preserved
        //
        // Null значения должны сохраниться
        $this->assertNotNull($result->filter->equal);
        $this->assertNull($result->filter->equal->relOwnerRelId);
    }

    public function testComplexRealWorldQuery(): void
    {
        // A real request:
        // /api/oneWinLink?filter[equal][owner][id]=1&filter[like][name]=test&page[number]=2&page[size]=50
        //
        // Реальный запрос:
        // /api/oneWinLink?filter[equal][owner][id]=1&filter[like][name]=test&page[number]=2&page[size]=50
        $rawQuery = [
            "filter" => [
                "equal" => [
                    "owner" => ["id" => "1"],
                ],
                "like" => [
                    "name" => "test",
                ],
            ],
            "page" => [
                "number" => "2",
                "size" => "50",
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        // Check the filters
        //
        // Проверяем фильтры
        $this->assertSame(1, $result->filter->equal->relOwnerRelId);
        $this->assertSame("test", $result->filter->like->name);

        // Check the pagination
        //
        // Проверяем пагинацию
        $this->assertSame(2, $result->pagination->number);
        $this->assertSame(50, $result->pagination->size);
    }

    // ==================== Helper Methods ====================
    //
    // ==================== Вспомогательные методы ====================

    private function setupConfigWithInOperator(): void
    {
        $accountModel = new Model(
            class: "TestOneWinAccount",
            type: "oneWinAccount",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $linkModel = new Model(
            class: "TestOneWinLink",
            type: "oneWinLink",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "owner",
                    type: "manyToOne",
                    targetModel: "oneWinAccount",
                    getter: "getOwner",
                    setter: "setOwner",
                ),
            ],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "oneWinLink",
            config: new ListActionConfig(
                class: $this->stubClass("TestOneWinLinkListRequest"),
                repository: $this->stubClass("TestOneWinLinkRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "owner.id",
                        operators: [FilterOperator::In],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$linkModel, $accountModel],
            actions: [$listAction],
            locators: [],
        );

        // setUp has already configured the mapper; the config is set once — recreate it
        //
        // setUp уже сконфигурировал маппер; конфиг задаётся один раз — пересоздаём
        $this->mapper = new MappingToListAction(new TypeSpecParser(), config: $this->config);
    }

    public function testCustomTypeSpecOverridesAttributeType(): void
    {
        $this->setupConfigWithCustomTypeSpec();

        $rawQuery = [
            "filter" => [
                "equal" => [
                    "originalId" => "42",
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        $this->assertNotNull($result->filter->equal);
        $this->assertSame(42, $result->filter->equal->originalId);
    }

    public function testCustomNullableTypeSpecAllowsNull(): void
    {
        $this->setupConfigWithCustomTypeSpec();

        $rawQuery = [
            "filter" => [
                "equal" => [
                    "originalId" => null,
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        $this->assertNotNull($result->filter->equal);
        $this->assertNull($result->filter->equal->originalId);
    }

    public function testCustomTypeSpecListConvertsEachElement(): void
    {
        $this->setupConfigWithCustomTypeSpecList();

        $rawQuery = [
            "filter" => [
                "in" => [
                    "owner" => ["id" => ["1", "2", "3"]],
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "oneWinLink");

        $this->assertNotNull($result->filter->in);
        $this->assertSame([1, 2, 3], $result->filter->in->relOwnerRelId);
    }

    private function setupConfigWithCustomTypeSpec(): void
    {
        if (!class_exists("TestCustomTypeLink")) {
            eval('
                class TestCustomTypeLink {}
            ');
        }

        if (!class_exists("TestCustomTypeLinkEqual")) {
            eval('
                class TestCustomTypeLinkEqual {
                    public ?int $originalId = null;
                }
            ');
        }

        if (!class_exists("TestCustomTypeLinkFilter")) {
            eval('
                class TestCustomTypeLinkFilter {
                    public ?TestCustomTypeLinkEqual $equal = null;
                }
            ');
        }

        if (!class_exists("TestCustomTypeLinkListRequest")) {
            eval('
                class TestCustomTypeLinkListRequest {
                    public ?TestCustomTypeLinkFilter $filter = null;
                }
            ');
        }

        $typeParser = new TypeSpecParser();

        $linkModel = new Model(
            class: "TestCustomTypeLink",
            type: "oneWinLink",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "originalId",
                    type: "string",
                    getter: "getOriginalId",
                    setter: "setOriginalId",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "oneWinLink",
            config: new ListActionConfig(
                class: $this->stubClass("TestCustomTypeLinkListRequest"),
                repository: $this->stubClass("TestOneWinLinkRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "originalId",
                        operators: [FilterOperator::Equal],
                        required: false,
                        typeSpec: $typeParser->parse("int|null"),
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$linkModel],
            actions: [$listAction],
            locators: [],
        );

        // setUp has already configured the mapper; the config is set once — recreate it
        //
        // setUp уже сконфигурировал маппер; конфиг задаётся один раз — пересоздаём
        $this->mapper = new MappingToListAction(new TypeSpecParser(), config: $this->config);
    }

    private function setupConfigWithCustomTypeSpecList(): void
    {
        if (!class_exists("TestCustomTypeListLink")) {
            eval('
                class TestCustomTypeListLink {}
            ');
        }

        if (!class_exists("TestCustomTypeListLinkIn")) {
            eval('
                class TestCustomTypeListLinkIn {
                    public ?array $relOwnerRelId = null;
                }
            ');
        }

        if (!class_exists("TestCustomTypeListLinkFilter")) {
            eval('
                class TestCustomTypeListLinkFilter {
                    public ?TestCustomTypeListLinkIn $in = null;
                }
            ');
        }

        if (!class_exists("TestCustomTypeListLinkListRequest")) {
            eval('
                class TestCustomTypeListLinkListRequest {
                    public ?TestCustomTypeListLinkFilter $filter = null;
                }
            ');
        }

        $typeParser = new TypeSpecParser();

        $accountModel = new Model(
            class: "TestOneWinAccount",
            type: "oneWinAccount",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "string",
                    getter: "getId",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $linkModel = new Model(
            class: "TestCustomTypeListLink",
            type: "oneWinLink",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int|null",
                    getter: "getId",
                    constructorParam: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "owner",
                    type: "manyToOne",
                    targetModel: "oneWinAccount",
                    getter: "getOwner",
                    setter: "setOwner",
                ),
            ],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "oneWinLink",
            config: new ListActionConfig(
                class: $this->stubClass("TestCustomTypeListLinkListRequest"),
                repository: $this->stubClass("TestOneWinLinkRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "owner.id",
                        operators: [FilterOperator::In],
                        required: false,
                        typeSpec: $typeParser->parse("int"),
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$linkModel, $accountModel],
            actions: [$listAction],
            locators: [],
        );

        // setUp has already configured the mapper; the config is set once — recreate it
        //
        // setUp уже сконфигурировал маппер; конфиг задаётся один раз — пересоздаём
        $this->mapper = new MappingToListAction(new TypeSpecParser(), config: $this->config);
    }
}
