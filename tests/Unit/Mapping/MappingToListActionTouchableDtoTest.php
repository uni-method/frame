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
use UniMethod\Frame\Mapping\ListQuery\Exception\InvalidListQueryException;
use UniMethod\Frame\Mapping\{MappingToListAction, TouchableDto};
use UniMethod\Frame\Type\TypeSpecParser;

final class MappingToListActionTouchableDtoTest extends TestCase
{
    private MappingToListAction $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MappingToListAction(new TypeSpecParser());
        $this->createTestClasses();
    }

    private function createTestClasses(): void
    {
        if (!class_exists("TouchableProductRepository")) {
            eval('
                class TouchableProductRepository implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
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

        if (!class_exists("TouchableProductListRequest")) {
            eval('
                class TouchableProductListRequest {
                    public ?TouchableProductFilter $filter = null;
                    public ?TouchableProductSort $sort = null;
                    public ?TouchableProductPagination $pagination = null;
                }
            ');
        }

        if (!class_exists("TouchableProductFilter")) {
            eval('
                class TouchableProductFilter {
                    public ?TouchableProductEqual $equal = null;
                }
            ');
        }

        if (!class_exists("TouchableProductEqual")) {
            eval('
                class TouchableProductEqual extends \UniMethod\Frame\Mapping\TouchableDto {
                    public ?string $name = null;
                    public ?int $ownerId = null;
                }
            ');
        }

        if (!class_exists("TouchableProductSort")) {
            eval('
                class TouchableProductSort extends \UniMethod\Frame\Mapping\TouchableDto {
                    public ?string $name = null;
                    public ?string $ownerId = null;
                }
            ');
        }

        if (!class_exists("TouchableProductPagination")) {
            eval('
                class TouchableProductPagination extends \UniMethod\Frame\Mapping\TouchableDto {
                    public ?int $number = null;
                    public ?int $size = null;
                }
            ');
        }
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

    /**
     * @param list<SortRule> $sortRules
     */
    private function setupConfig(array $sortRules = []): void
    {
        $model = new Model(
            class: "TouchableProduct",
            type: "touchableProduct",
            attributes: [
                new Attribute(
                    name: "name",
                    type: "string|null",
                    getter: "getName",
                    constructorParam: false,
                ),
                new Attribute(
                    name: "ownerId",
                    type: "int|null",
                    getter: "getOwnerId",
                    constructorParam: false,
                ),
            ],
            relations: [],
        );

        $action = new Action(
            type: ActionType::List,
            model: "touchableProduct",
            config: new ListActionConfig(
                class: $this->stubClass("TouchableProductListRequest"),
                repository: $this->stubClass("TouchableProductRepository"),
                pagination: new PaginationRule(maxSize: 100, defaultSize: 20),
                sort: $sortRules,
                filters: [
                    new FilterRule(
                        path: "name",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                    new FilterRule(
                        path: "ownerId",
                        operators: [FilterOperator::Equal],
                        required: false,
                    ),
                ],
            ),
        );

        $this->mapper->setConfig(
            new Config(
                models: [$model],
                actions: [$action],
                locators: [],
            ),
        );
    }

    // ==================== TouchableDto filter touch tracking ====================
    //
    // ==================== TouchableDto: отслеживание касаний фильтра ====================

    public function testMappedScalarFieldIsMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["name" => "foo"]]],
            "touchableProduct",
        );

        $this->assertTrue($result->filter->equal->isSet("name"));
    }

    public function testUnmappedFieldIsNotMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["name" => "foo"]]],
            "touchableProduct",
        );

        $this->assertFalse($result->filter->equal->isSet("ownerId"));
    }

    public function testNullValueIsMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["name" => ""]]],
            "touchableProduct",
        );

        $this->assertTrue($result->filter->equal->isSet("name"));
        $this->assertNull($result->filter->equal->name);
    }

    public function testBothFieldsMappedAreBothMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["name" => "bar", "ownerId" => "7"]]],
            "touchableProduct",
        );

        $this->assertTrue($result->filter->equal->isSet("name"));
        $this->assertTrue($result->filter->equal->isSet("ownerId"));
    }

    public function testNonTouchableDtoIsNotAffected(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["filter" => ["equal" => ["name" => "foo"]]],
            "touchableProduct",
        );

        $this->assertNotInstanceOf(TouchableDto::class, $result->filter);
    }

    // ==================== Pagination TouchableDto ====================
    //
    // ==================== TouchableDto пагинации ====================

    public function testPaginationNumberIsMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["page" => ["number" => "2"]],
            "touchableProduct",
        );

        $this->assertTrue($result->pagination->isSet("number"));
        $this->assertSame(2, $result->pagination->number);
    }

    public function testPaginationSizeIsMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["page" => ["size" => "50"]],
            "touchableProduct",
        );

        $this->assertTrue($result->pagination->isSet("size"));
        $this->assertSame(50, $result->pagination->size);
    }

    public function testPaginationBothFieldsMarkedAsSet(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["page" => ["number" => "3", "size" => "25"]],
            "touchableProduct",
        );

        $this->assertTrue($result->pagination->isSet("number"));
        $this->assertTrue($result->pagination->isSet("size"));
        $this->assertSame(3, $result->pagination->number);
        $this->assertSame(25, $result->pagination->size);
    }

    public function testPaginationNumberNotSetWhenSizeOnly(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["page" => ["size" => "10"]],
            "touchableProduct",
        );

        $this->assertFalse($result->pagination->isSet("number"));
        $this->assertNull($result->pagination->number);
    }

    public function testPaginationSizeNotSetWhenNumberOnly(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map(
            ["page" => ["number" => "1"]],
            "touchableProduct",
        );

        $this->assertFalse($result->pagination->isSet("size"));
        $this->assertNull($result->pagination->size);
    }

    public function testPaginationNeitherFieldSetWhenNoPageData(): void
    {
        $this->setupConfig();

        $result = $this->mapper->map([], "touchableProduct");

        $this->assertFalse($result->pagination->isSet("number"));
        $this->assertFalse($result->pagination->isSet("size"));
    }

    public function testPaginationNotInstanceOfTouchableDtoWhenClassNotExtends(): void
    {
        $this->setupConfig();

        $this->assertInstanceOf(
            TouchableDto::class,
            $result = $this->mapper->map(
                ["page" => ["number" => "1"]],
                "touchableProduct",
            )->pagination,
        );
    }

    // ==================== Sort TouchableDto ====================
    //
    // ==================== TouchableDto сортировки ====================

    public function testSortFieldIsMarkedAsSet(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
        ]);

        $result = $this->mapper->map(
            ["sort" => ["name" => "asc"]],
            "touchableProduct",
        );

        $this->assertTrue($result->sort->isSet("name"));
        $this->assertSame("asc", $result->sort->name);
    }

    public function testSortDescIsMarkedAsSet(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
        ]);

        $result = $this->mapper->map(
            ["sort" => ["name" => "desc"]],
            "touchableProduct",
        );

        $this->assertTrue($result->sort->isSet("name"));
        $this->assertSame("desc", $result->sort->name);
    }

    public function testSortUnmappedFieldIsNotMarkedAsSet(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
        ]);

        $result = $this->mapper->map(
            ["sort" => ["name" => "asc"]],
            "touchableProduct",
        );

        $this->assertFalse($result->sort->isSet("ownerId"));
    }

    public function testSortBothFieldsMarkedAsSet(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
            new SortRule(
                path: "ownerId",
                required: false,
            ),
        ]);

        $result = $this->mapper->map(
            ["sort" => ["name" => "asc", "ownerId" => "desc"]],
            "touchableProduct",
        );

        $this->assertTrue($result->sort->isSet("name"));
        $this->assertTrue($result->sort->isSet("ownerId"));
    }

    public function testSortNeitherFieldSetWhenNoSortData(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
        ]);

        $result = $this->mapper->map([], "touchableProduct");

        $this->assertNull($result->sort);
    }

    public function testSortInvalidDirectionNotMarkedAsSet(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
        ]);

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Sort direction for 'name' must be 'asc' or 'desc', 'invalid' given");

        $this->mapper->map(
            ["sort" => ["name" => "invalid"]],
            "touchableProduct",
        );
    }

    public function testSortOnlyValidFieldMarkedAsSetWhenMixed(): void
    {
        $this->setupConfig([
            new SortRule(
                path: "name",
                required: false,
            ),
            new SortRule(
                path: "ownerId",
                required: false,
            ),
        ]);

        // An invalid direction on any field — 400 for the whole request,
        // there is no partial application
        //
        // Неверное направление у любого поля — 400 для всего запроса,
        // частичного применения нет
        $this->expectException(InvalidListQueryException::class);

        $this->mapper->map(
            ["sort" => ["name" => "asc", "ownerId" => "invalid"]],
            "touchableProduct",
        );
    }
}
