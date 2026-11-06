<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Config, Model};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterRule,
    ListActionConfig,
    PaginationRule,
    SortRule,
};
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestProductEqual,
    TestProductFilter,
    TestProductListRequest,
    TestProductPagination,
    TestProductRepository,
    TestProductSort,
};
use UniMethod\Frame\Type\TypeSpecParser;

final class MappingToListActionTest extends TestCase
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
     * Stub classes are created via eval(), so static analysis does not
     * see them: the name is built dynamically and marked as class-string.
     *
     * Стаб-классы создаются через eval(), поэтому статический анализ их не
     * видит: имя собирается динамически и помечается как class-string.
     *
     * @return class-string
     */
    private function stubClass(string $fqcn): string
    {
        /** @var class-string $class */
        $class = $fqcn;

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

    // ==================== Pagination Always Created Tests ====================
    //
    // ==================== Тесты: пагинация создаётся всегда ====================

    public function testPaginationAlwaysCreatedWhenNotProvided(): void
    {
        $this->setupConfigWithoutRequired();

        /** @var object{pagination: object|null} $result */
        $result = $this->mapper->map([], "product");

        $this->assertNotNull($result->pagination);
        $this->assertInstanceOf(
            TestProductPagination::class,
            $result->pagination,
        );
    }

    public function testPaginationWithDefaultValues(): void
    {
        $this->setupConfigWithoutRequired();

        $result = $this->mapper->map([], "product");

        // There must be default values from the configuration
        //
        // Должны быть дефолтные значения из конфигурации
        $this->assertSame(100, $result->pagination->maxSize);
        $this->assertSame(20, $result->pagination->defaultSize);
        $this->assertNull($result->pagination->number);
        $this->assertNull($result->pagination->size);
    }

    public function testPaginationWithProvidedValues(): void
    {
        $this->setupConfigWithoutRequired();

        $rawQuery = [
            "page" => [
                "number" => "2",
                "size" => "50",
            ],
        ];

        $result = $this->mapper->map($rawQuery, "product");

        $this->assertNotNull($result->pagination);
        $this->assertSame(2, $result->pagination->number);
        $this->assertSame(50, $result->pagination->size);
    }

    // ==================== Required Filter Tests ====================
    //
    // ==================== Тесты обязательных фильтров ====================

    public function testRequiredFilterCreatesFilterObjectWhenNotProvided(): void
    {
        $this->setupConfigWithRequiredFilter();

        $result = $this->mapper->map([], "product");

        $this->assertNotNull($result->filter);
        $this->assertInstanceOf(TestProductFilter::class, $result->filter);
    }

    public function testRequiredFilterCreatesOperatorObjectWithNullValues(): void
    {
        $this->setupConfigWithRequiredFilter();

        /** @var object{filter: object{equal: object{name: string|null}|null}|null, sort: object{createdAt: string|null}|null, pagination: object{number: int|null, size: int|null}|null} $result */
        $result = $this->mapper->map([], "product");

        $this->assertNotNull($result->filter->equal);
        $this->assertInstanceOf(
            TestProductEqual::class,
            $result->filter->equal,
        );

        $this->assertNull($result->filter->equal->category);
        $this->assertNull($result->filter->equal->inStock);
    }

    public function testRequiredFilterWithMultipleOperatorsCreatesAll(): void
    {
        $this->setupConfigWithRequiredFilterMultipleOperators();

        // Empty query
        //
        // Пустой query
        $result = $this->mapper->map([], "product");

        // Both operators must be created
        //
        // Оба оператора должны быть созданы
        $this->assertNotNull($result->filter->equal);
        $this->assertNotNull($result->filter->in);

        // Fields are null
        //
        // Поля null
        $this->assertNull($result->filter->equal->category);
        $this->assertNull($result->filter->in->status);
    }

    public function testRequiredFilterWithProvidedDataFillsValues(): void
    {
        $this->setupConfigWithRequiredFilter();

        $rawQuery = [
            "filter" => [
                "equal" => [
                    "category" => "electronics",
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "product");

        // The passed value must be filled
        //
        // Переданное значение должно быть заполнено
        $this->assertSame("electronics", $result->filter->equal->category);
        // The other required field stays null
        //
        // Другое required поле остаётся null
        $this->assertNull($result->filter->equal->inStock);
    }

    public function testNonRequiredFilterNotCreatedWhenNotProvided(): void
    {
        $this->setupConfigWithoutRequired();

        // Empty query
        //
        // Пустой query
        $result = $this->mapper->map([], "product");

        // Filter must NOT be created (there are no required filters)
        //
        // Filter НЕ должен быть создан (нет required фильтров)
        $this->assertNull($result->filter);
    }

    // ==================== Required Sort Tests ====================
    //
    // ==================== Тесты обязательной сортировки ====================

    public function testRequiredSortCreatesSortObjectWhenNotProvided(): void
    {
        $this->setupConfigWithRequiredSort();

        $result = $this->mapper->map([], "product");

        $this->assertNotNull($result->sort);
        $this->assertInstanceOf(TestProductSort::class, $result->sort);
    }

    public function testRequiredSortFieldsAreNullForValidation(): void
    {
        $this->setupConfigWithRequiredSort();

        // Empty query
        //
        // Пустой query
        $result = $this->mapper->map([], "product");

        // All fields must be null - for validation
        //
        // Все поля должны быть null - для валидации
        $this->assertNull($result->sort->price);
        $this->assertNull($result->sort->createdAt);
    }

    public function testRequiredSortWithProvidedDataFillsValues(): void
    {
        $this->setupConfigWithRequiredSort();

        $rawQuery = [
            "sort" => [
                "price" => "asc",
            ],
        ];

        $result = $this->mapper->map($rawQuery, "product");

        // The passed value must be filled
        //
        // Переданное значение должно быть заполнено
        $this->assertSame("asc", $result->sort->price);
        // The other required field stays null
        //
        // Другое required поле остаётся null
        $this->assertNull($result->sort->createdAt);
    }

    public function testNonRequiredSortNotCreatedWhenNotProvided(): void
    {
        $this->setupConfigWithoutRequired();

        // Empty query
        //
        // Пустой query
        $result = $this->mapper->map([], "product");

        // Sort must NOT be created (there is no required sort)
        //
        // Sort НЕ должен быть создан (нет required сортировки)
        $this->assertNull($result->sort);
    }

    // ==================== Combined Required Tests ====================
    //
    // ==================== Тесты комбинации обязательных ====================

    public function testAllComponentsWithRequiredAndProvided(): void
    {
        $this->setupConfigWithAllRequired();

        $rawQuery = [
            "filter" => [
                "equal" => [
                    "category" => "books",
                ],
            ],
            "sort" => [
                "price" => "desc",
            ],
            "page" => [
                "number" => "3",
                "size" => "25",
            ],
        ];

        $result = $this->mapper->map($rawQuery, "product");

        // All components must be created and filled
        //
        // Все компоненты должны быть созданы и заполнены
        $this->assertNotNull($result->filter);
        $this->assertSame("books", $result->filter->equal->category);

        $this->assertNotNull($result->sort);
        $this->assertSame("desc", $result->sort->price);

        $this->assertNotNull($result->pagination);
        $this->assertSame(3, $result->pagination->number);
        $this->assertSame(25, $result->pagination->size);
    }

    public function testAllComponentsWithRequiredButNotProvided(): void
    {
        $this->setupConfigWithAllRequired();

        // A completely empty query
        //
        // Полностью пустой query
        $result = $this->mapper->map([], "product");

        // All components must be created with null values
        //
        // Все компоненты должны быть созданы с null значениями
        $this->assertNotNull($result->filter);
        $this->assertNotNull($result->filter->equal);
        $this->assertNull($result->filter->equal->category);

        $this->assertNotNull($result->sort);
        $this->assertNull($result->sort->price);

        $this->assertNotNull($result->pagination);
        $this->assertNull($result->pagination->number);
        $this->assertNull($result->pagination->size);
    }

    // ==================== Config Setup Helpers ====================
    //
    // ==================== Хелперы настройки конфига ====================

    private function setupConfigWithoutRequired(): void
    {
        $productModel = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "category",
                    type: "string",
                    getter: "getCategory",
                    setter: "setCategory",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "inStock",
                    type: "bool",
                    getter: "isInStock",
                    setter: "setInStock",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "status",
                    type: "string",
                    getter: "getStatus",
                    setter: "setStatus",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "price",
                    type: "float",
                    getter: "getPrice",
                    setter: "setPrice",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "createdAt",
                    type: "datetime",
                    getter: "getCreatedAt",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
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
                    new SortRule(
                        path: "createdAt",
                        required: false,
                    ),
                ],
                filters: [
                    new FilterRule(
                        path: "category",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "inStock",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "status",
                        operators: [FilterOperator::In],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$productModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    private function setupConfigWithRequiredFilter(): void
    {
        $productModel = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "category",
                    type: "string",
                    getter: "getCategory",
                    setter: "setCategory",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "inStock",
                    type: "bool",
                    getter: "isInStock",
                    setter: "setInStock",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: TestProductListRequest::class,
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "category",
                        operators: [FilterOperator::Equal],
                        required: true,
                    ),
                    new FilterRule(
                        path: "inStock",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$productModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    private function setupConfigWithRequiredFilterMultipleOperators(): void
    {
        $productModel = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "category",
                    type: "string",
                    getter: "getCategory",
                    setter: "setCategory",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "status",
                    type: "string",
                    getter: "getStatus",
                    setter: "setStatus",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: TestProductListRequest::class,
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "category",
                        operators: [FilterOperator::Equal, FilterOperator::In],
                        required: true,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$productModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    private function setupConfigWithRequiredSort(): void
    {
        $productModel = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "price",
                    type: "float",
                    getter: "getPrice",
                    setter: "setPrice",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "createdAt",
                    type: "datetime",
                    getter: "getCreatedAt",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: TestProductListRequest::class,
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [
                    new SortRule(
                        path: "price",
                        required: true,
                    ),
                    new SortRule(
                        path: "createdAt",
                        required: false,
                    ),
                ],
                filters: [],
            ),
        );

        $this->config = new Config(
            models: [$productModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    private function setupConfigWithAllRequired(): void
    {
        $productModel = new Model(
            class: "TestProduct",
            type: "product",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
                    getter: "getId",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "category",
                    type: "string",
                    getter: "getCategory",
                    setter: "setCategory",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "price",
                    type: "float",
                    getter: "getPrice",
                    setter: "setPrice",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "product",
            config: new ListActionConfig(
                class: TestProductListRequest::class,
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [
                    new SortRule(
                        path: "price",
                        required: true,
                    ),
                ],
                filters: [
                    new FilterRule(
                        path: "category",
                        operators: [FilterOperator::Equal],
                        required: true,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$productModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    // ==================== Namespace Tests ====================
    //
    // ==================== Тесты namespace ====================

    public function testNamespaceFromConfigClass(): void
    {
        $this->createNamespacedTestClasses();
        $this->setupConfigWithExplicitNamespace();

        $rawQuery = [
            "filter" => [
                "equal" => [
                    "name" => "Test User",
                ],
            ],
            "sort" => [
                "createdAt" => "desc",
            ],
            "page" => [
                "number" => "1",
                "size" => "10",
            ],
        ];

        $result = $this->mapper->map($rawQuery, "user");

        // @phpstan-ignore argument.type
        $this->assertInstanceOf("App\\Stub\\Models\\UserListRequest", $result);
        $this->assertInstanceOf(
            // @phpstan-ignore argument.type
            "App\\Stub\\Models\\UserFilter",
            $result->filter,
        );
        $this->assertInstanceOf(
            // @phpstan-ignore argument.type
            "App\\Stub\\Models\\UserEqual",
            $result->filter->equal,
        );
        // @phpstan-ignore argument.type
        $this->assertInstanceOf("App\\Stub\\Models\\UserSort", $result->sort);
        $this->assertInstanceOf(
            // @phpstan-ignore argument.type
            "App\\Stub\\Models\\UserPagination",
            $result->pagination,
        );

        $this->assertSame("Test User", $result->filter->equal->name);
        $this->assertSame("desc", $result->sort->createdAt);
        $this->assertSame(1, $result->pagination->number);
        $this->assertSame(10, $result->pagination->size);
    }

    public function testThrowsExceptionWhenRepositoryNotSet(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Config repository must be set for model 'user'",
        );

        $userModel = new Model(
            class: "App\\Models\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
                    getter: "getId",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "user",
            config: new ListActionConfig(
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [],
            ),
        );

        $config = new Config(
            models: [$userModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($config);
        $this->mapper->map([], "user");
    }

    public function testNamespaceWithCustomRootClass(): void
    {
        $this->createNamespacedTestClassesForCustomRoot();
        $this->setupConfigWithNamespaceAndCustomRootClass();

        $rawQuery = [
            "filter" => [
                "equal" => [
                    "name" => "Custom Test",
                ],
            ],
        ];

        $result = $this->mapper->map($rawQuery, "user");

        // @phpstan-ignore argument.type
        $this->assertInstanceOf("App\\Stub\\DTO\\CustomUserRequest", $result);
        // @phpstan-ignore argument.type
        $this->assertInstanceOf("App\\Stub\\DTO\\UserFilter", $result->filter);
        $this->assertInstanceOf(
            // @phpstan-ignore argument.type
            "App\\Stub\\DTO\\UserEqual",
            $result->filter->equal,
        );
    }

    public function testNamespaceWithRequiredFilter(): void
    {
        $this->createNamespacedTestClasses();
        $this->setupConfigWithNamespaceAndRequired();

        $result = $this->mapper->map([], "user");

        $this->assertNotNull($result->filter);
        $this->assertInstanceOf(
            // @phpstan-ignore argument.type
            "App\\Stub\\Models\\UserFilter",
            $result->filter,
        );
        $this->assertNotNull($result->filter->equal);
        $this->assertInstanceOf(
            // @phpstan-ignore argument.type
            "App\\Stub\\Models\\UserEqual",
            $result->filter->equal,
        );

        $this->assertNull($result->filter->equal->name);
    }

    private function createNamespacedTestClasses(): void
    {
        if (!class_exists("App\\Stub\\Models\\UserListRequest")) {
            eval('
                namespace App\Stub\Models;

                class UserListRequest {
                    public ?UserFilter $filter = null;
                    public ?UserSort $sort = null;
                    public ?UserPagination $pagination = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\Models\\UserFilter")) {
            eval('
                namespace App\Stub\Models;

                class UserFilter {
                    public ?UserEqual $equal = null;
                    public ?UserIn $in = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\Models\\UserEqual")) {
            eval('
                namespace App\Stub\Models;

                class UserEqual {
                    public ?string $name = null;
                    public ?string $email = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\Models\\UserIn")) {
            eval('
                namespace App\Stub\Models;

                class UserIn {
                    public ?array $status = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\Models\\UserSort")) {
            eval('
                namespace App\Stub\Models;

                class UserSort {
                    public ?string $name = null;
                    public ?string $createdAt = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\Models\\UserPagination")) {
            eval('
                namespace App\Stub\Models;

                class UserPagination {
                    public ?int $maxSize = 100;
                    public int $defaultSize = 20;
                    public ?int $number = null;
                    public ?int $size = null;
                }
            ');
        }
    }

    private function createNamespacedTestClassesForCustomRoot(): void
    {
        if (!class_exists("App\\Stub\\DTO\\CustomUserRequest")) {
            eval('
                namespace App\Stub\DTO;

                class CustomUserRequest {
                    public ?UserFilter $filter = null;
                    public ?UserSort $sort = null;
                    public ?UserPagination $pagination = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\DTO\\UserFilter")) {
            eval('
                namespace App\Stub\DTO;

                class UserFilter {
                    public ?UserEqual $equal = null;
                    public ?UserIn $in = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\DTO\\UserEqual")) {
            eval('
                namespace App\Stub\DTO;

                class UserEqual {
                    public ?string $name = null;
                    public ?string $email = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\DTO\\UserIn")) {
            eval('
                namespace App\Stub\DTO;

                class UserIn {
                    public ?array $status = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\DTO\\UserSort")) {
            eval('
                namespace App\Stub\DTO;

                class UserSort {
                    public ?string $name = null;
                    public ?string $createdAt = null;
                }
            ');
        }

        if (!class_exists("App\\Stub\\DTO\\UserPagination")) {
            eval('
                namespace App\Stub\DTO;

                class UserPagination {
                    public ?int $maxSize = 100;
                    public int $defaultSize = 20;
                    public ?int $number = null;
                    public ?int $size = null;
                }
            ');
        }
    }

    private function setupConfigWithExplicitNamespace(): void
    {
        $userModel = new Model(
            class: "App\\Models\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
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
                new Attribute(
                    name: "email",
                    type: "string",
                    getter: "getEmail",
                    setter: "setEmail",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "status",
                    type: "string",
                    getter: "getStatus",
                    setter: "setStatus",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "createdAt",
                    type: "datetime",
                    getter: "getCreatedAt",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $listAction = new Action(
            type: ActionType::List,
            model: "user",
            config: new ListActionConfig(
                class: $this->stubClass("App\\Stub\\Models\\UserListRequest"),
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [
                    new SortRule(
                        path: "name",
                        required: false,
                    ),
                    new SortRule(
                        path: "createdAt",
                        required: false,
                    ),
                ],
                filters: [
                    new FilterRule(
                        path: "name",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "email",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "status",
                        operators: [FilterOperator::In],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$userModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    private function setupConfigWithNamespaceAndCustomRootClass(): void
    {
        $userModel = new Model(
            class: "App\\Models\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
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

        $listAction = new Action(
            type: ActionType::List,
            model: "user",
            config: new ListActionConfig(
                class: $this->stubClass("App\\Stub\\DTO\\CustomUserRequest"),
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "name",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$userModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }

    private function setupConfigWithNamespaceAndRequired(): void
    {
        $userModel = new Model(
            class: "App\\Models\\User",
            type: "user",
            attributes: [
                new Attribute(
                    name: "id",
                    type: "int",
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

        $listAction = new Action(
            type: ActionType::List,
            model: "user",
            config: new ListActionConfig(
                class: $this->stubClass("App\\Stub\\Models\\UserListRequest"),
                repository: TestProductRepository::class,
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: [],
                filters: [
                    new FilterRule(
                        path: "name",
                        operators: [FilterOperator::Equal],
                        required: true,
                    ),
                ],
            ),
        );

        $this->config = new Config(
            models: [$userModel],
            actions: [$listAction],
            locators: [],
        );

        $this->mapper->setConfig($this->config);
    }
}
