<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Config, Model};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterRule,
    ListActionConfig,
    PaginationRule,
    SortRule,
};
use UniMethod\Frame\Mapping\ListQuery\Exception\InvalidListQueryException;
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestProductFilter,
    TestProductListRequest,
    TestProductRepository,
    TestProductSort,
};
use UniMethod\Frame\Type\TypeSpecParser;

final class MappingToListActionSlopCodeCoverTest extends TestCase
{
    private MappingToListAction $mapper;

    private Config $config;

    protected function setUp(): void
    {
        $typeParser = new TypeSpecParser();
        $this->mapper = new MappingToListAction($typeParser);
        $this->createTestClasses();
    }

    /**
     * Stub classes are created via eval() or do not exist at all (cases about
     * a non-existent class), so static analysis does not see them.
     *
     * Стаб-классы создаются через eval() либо не существуют вовсе (кейсы про
     * несуществующий класс), поэтому статический анализ их не видит.
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
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductRepository",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductRepository implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
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
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductListRequest",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductListRequest {
                    public ?TestProductFilter $filter = null;
                    public ?TestProductSort $sort = null;
                    public ?TestProductPagination $pagination = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductFilter",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductFilter {
                    public ?TestProductEqual $equal = null;
                    public ?TestProductIn $in = null;
                    public $nonExistentOperator = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductEqual",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductEqual {
                    public ?string $category = null;
                    public ?bool $inStock = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductIn",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductIn {
                    public ?array $status = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductSort",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductSort {
                    public ?string $price = null;
                    public ?string $createdAt = null;
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductPagination",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestProductPagination {
                    public ?int $maxSize = 100;
                    public int $defaultSize = 20;
                    public ?int $number = null;
                    public ?int $size = null;
                }
            ');
        }
    }

    private function setupConfigWithoutRequired(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
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
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: TestProductListRequest::class,
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [
                    new SortRule(
                        path: "price",
                        required: false,
                    ),
                ],
                filters: [
                    new FilterRule(
                        path: "category",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);
    }

    // ==================== map() Tests ====================
    //
    // ==================== Тесты map() ====================

    public function testMapThrowsWhenConfigNotSet(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Config not set. Use setConfig() first.");

        $this->mapper->map([], "product");
    }

    public function testMapThrowsWhenListActionConfigNotSet(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $action = new Action(
            type: ActionType::Create,
            model: "product",
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "List action not found for model 'product'",
        );

        $this->mapper->map([], "product");
    }

    public function testMapThrowsWhenRepositoryNotSet(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: $this->stubClass("TestProductListRequest"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [],
            ),
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Config repository must be set for model 'product'",
        );

        $this->mapper->map([], "product");
    }

    public function testMapThrowsWhenRepositoryDoesNotImplementEntityCollectionInterface(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: $this->stubClass("TestProductListRequest"),
                repository: $this->stubClass("InvalidRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [],
            ),
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Repository for model 'product' must implements EntityCollectionInterface",
        );

        $this->mapper->map([], "product");
    }

    public function testMapReturnsNullWhenClassNotSet(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [],
            ),
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $result = $this->mapper->map([], "product");

        $this->assertNull($result);
    }

    public function testMapThrowsWhenRootClassNotFound(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: $this->stubClass("NonExistentClass"),
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [],
            ),
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Root class 'NonExistentClass' not found. Make sure to generate classes first.",
        );

        $this->mapper->map([], "product");
    }

    // ==================== findListAction() Tests ====================
    //
    // ==================== Тесты findListAction() ====================

    public function testFindListActionThrowsWhenNotFound(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $action = new Action(
            type: ActionType::Create,
            model: "product",
        );

        $this->config = new Config(
            models: [$model],
            actions: [$action],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "List action not found for model 'product'",
        );

        $reflection = new ReflectionClass($this->mapper);
        $method = $reflection->getMethod("findListAction");
        $method->invokeArgs($this->mapper, ["product"]);
    }

    // ==================== findModel() Tests ====================
    //
    // ==================== Тесты findModel() ====================

    public function testFindModelThrowsWhenNotFound(): void
    {
        $model = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [],
            relations: [],
        );

        $this->config = new Config(
            models: [$model],
            actions: [],
            locators: [],
        );
        $this->mapper->setConfig($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Model 'nonExistent' not found in config",
        );

        $reflection = new ReflectionClass($this->mapper);
        $method = $reflection->getMethod("findModel");
        $method->invokeArgs($this->mapper, ["nonExistent"]);
    }

    // ==================== mapFilter() Tests ====================
    //
    // ==================== Тесты mapFilter() ====================

    public function testMapFilterSkipsNonArrayFields(): void
    {
        $this->setupConfigWithoutRequired();

        $rawQuery = [
            "filter" => [
                "equal" => "notAnArray",
            ],
        ];

        /**
         * @var object{
         *     filter: \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductFilter|null
         * } $result
         */
        $result = $this->mapper->map($rawQuery, "product");

        $this->assertNotNull($result->filter);
        $this->assertInstanceOf(TestProductFilter::class, $result->filter);
        $this->assertNull($result->filter->equal);
    }

    public function testMapFilterRejectsUnknownOperatorKey(): void
    {
        $this->setupConfigWithoutRequired();

        $rawQuery = [
            "filter" => [
                "nonExistentOperator" => ["category" => "electronics"],
            ],
        ];

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage(
            "Unknown filter operator 'nonExistentOperator'",
        );

        $this->mapper->map($rawQuery, "product");
    }

    // ==================== mapSort() Tests ====================
    //
    // ==================== Тесты mapSort() ====================

    public function testMapSortRejectsNonStringDirections(): void
    {
        $this->setupConfigWithoutRequired();

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Sort direction for 'price' must be 'asc' or 'desc', bool given");

        $this->mapper->map(["sort" => ["price" => true]], "product");
    }

    public function testMapSortRejectsInvalidDirections(): void
    {
        $this->setupConfigWithoutRequired();

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Sort direction for 'price' must be 'asc' or 'desc', 'invalid' given");

        $this->mapper->map(["sort" => ["price" => "invalid"]], "product");
    }

    public function testMapSortRejectsFieldOutsideConfig(): void
    {
        $this->setupConfigWithoutRequired();

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Sort field 'ghost' is not allowed for model 'product'");

        $this->mapper->map(["sort" => ["ghost" => "asc"]], "product");
    }

    public function testMapSortRejectsScalarSortParameter(): void
    {
        $this->setupConfigWithoutRequired();

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Parameter 'sort' must be an object");

        $this->mapper->map(["sort" => "price"], "product");
    }

    public function testMapSortDirectionIsCaseInsensitive(): void
    {
        $this->setupConfigWithoutRequired();

        /**
         * @var object{
         *     sort: \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestProductSort|null
         * } $result
         */
        $result = $this->mapper->map(["sort" => ["price" => "DESC"]], "product");

        $this->assertInstanceOf(TestProductSort::class, $result->sort);
        $this->assertSame("desc", $result->sort->price);
    }
}
