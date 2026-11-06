<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\Stub;
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
use UniMethod\Frame\Mapping\ListQuery\Exception\{
    InvalidListQueryException,
    ListActionMisconfiguredException,
    UnsatisfiableListFilterException,
};
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Persistence\{
    EntityBatchLocatorInterface,
    EntityLocatorContainerInterface,
    EntityLocatorInterface,
};
use UniMethod\Frame\Tests\Unit\Mapping\Stub\RfUser;
use UniMethod\Frame\Type\TypeSpecParser;

/**
 * A filter by a whole relation: the path points to a relation, the value from the query is
 * the id of the target model, the loaded entity is put into the operator DTO.
 * The property name follows the general path rule: user -> user, a.b -> relARelB.
 *
 * Фильтр по связи целиком: путь указывает на связь, значение из query —
 * id целевой модели, в операторный DTO кладётся загруженная сущность.
 * Имя свойства — по общему правилу путей: user -> user, a.b -> relARelB.
 */
final class MappingToListActionRelationFilterTest extends TestCase
{
    private const string STUB_NS = "UniMethod\Frame\Tests\Unit\Mapping\Stub";

    private TypeSpecParser $typeParser;

    private EntityLocatorInterface&Stub $locator;

    private EntityLocatorContainerInterface&Stub $locators;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\RfGardenListRequest",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class RfUser {
                    public function __construct(public ?int $id = null) {}
                }

                class RfGarden {}

                class RfGardenRepository implements \UniMethod\Frame\Persistence\EntityCollectionInterface {
                    public function list(
                        object|null $filter = null,
                        object|null $sort = null,
                        object|null $pagination = null,
                        array $included = [],
                    ): array { return []; }
                    public function countForList(object|null $filter = null): int { return 0; }
                }

                class RfGardenListRequest {
                    public ?RfGardenFilter $filter = null;
                }

                class RfGardenFilter {
                    public ?RfGardenEqual $equal = null;
                    public ?RfGardenIn $in = null;
                }

                class RfGardenEqual {
                    public ?string $setupStatus = null;
                    public ?RfUser $user = null;
                    public ?int $relUserRelId = null;
                }

                class RfGardenIn {
                    public ?array $user = null;
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->typeParser = new TypeSpecParser();
        $this->locator = $this->createStub(EntityLocatorInterface::class);
        $this->locators = $this->createStub(
            EntityLocatorContainerInterface::class,
        );
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
     * @param FilterRule[] $filterRules
     * @param bool $withUserReadLocator whether the target model has a read action with a locator
     */
    private function makeMapper(
        array $filterRules,
        bool $withUserReadLocator = true,
        bool $withLocatorContainer = true,
        ?string $userModelLocator = null,
        ?string $defaultLocator = null,
    ): MappingToListAction {
        $mapper = new MappingToListAction(
            $this->typeParser,
            $withLocatorContainer ? $this->locators : null,
        );

        $user = new Model(
            class: self::STUB_NS . "\\RfUser",
            type: "rfUser",
            attributes: [new Attribute(name: "id", type: "int")],
            relations: [],
            entityLocator: $userModelLocator,
        );

        $garden = new Model(
            class: self::STUB_NS . "\\RfGarden",
            type: "rfGarden",
            attributes: [
                new Attribute(name: "id", type: "int"),
                new Attribute(name: "setupStatus", type: "string"),
            ],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::MANY_TO_ONE,
                    targetModel: "rfUser",
                ),
            ],
        );

        $actions = [
            new Action(
                type: ActionType::List,
                model: "rfGarden",
                config: new ListActionConfig(
                    class: $this->stubClass("RfGardenListRequest"),
                    repository: $this->stubClass("RfGardenRepository"),
                    pagination: new PaginationRule(
                        maxSize: 100,
                        defaultSize: 20,
                    ),
                    sort: [],
                    filters: $filterRules,
                ),
            ),
        ];

        if ($withUserReadLocator) {
            $actions[] = new Action(
                type: ActionType::Read,
                model: "rfUser",
                entityLocator: "doctrine",
            );
        }

        $mapper->setConfig(
            new Config(
                models: [$garden, $user],
                actions: $actions,
                locators: [
                    "doctrine" => EntityLocatorInterface::class,
                    "modelLoc" => EntityLocatorInterface::class,
                    "defaultLoc" => EntityLocatorInterface::class,
                ],
                defaultLocator: $defaultLocator,
            ),
        );

        return $mapper;
    }

    // ==================== Loading the entity ====================
    //
    // ==================== Загрузка сущности ====================

    public function testEqualByRelationLoadsEntityIntoDto(): void
    {
        $locators = $this->createMock(EntityLocatorContainerInterface::class);
        $this->locators = $locators;
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        $entity = new RfUser(5);

        $locators
            ->expects($this->once())
            ->method("getEntityLocatorByName")
            ->with("doctrine")
            ->willReturn($locator);

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(self::STUB_NS . "\\RfUser", 5)
            ->willReturn($entity);

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::Equal]),
        ]);

        $result = $mapper->map(
            ["filter" => ["equal" => ["user" => "5"]]],
            "rfGarden",
        );

        $this->assertSame($entity, $result->filter->equal->user);
    }

    public function testInByRelationLoadsEveryEntity(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        $first = new RfUser(5);
        $second = new RfUser(7);

        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($locator);

        $locator
            ->expects($this->exactly(2))
            ->method("find")
            ->willReturnCallback(
                static fn (
                    string $className,
                    int|string $id,
                ): RfUser => $id === 5 ? $first : $second,
            );

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::In]),
        ]);

        $result = $mapper->map(
            ["filter" => ["in" => ["user" => ["5", "7"]]]],
            "rfGarden",
        );

        $this->assertSame([$first, $second], $result->filter->in->user);
    }

    public function testRelationAndItsFieldAreIndependentRules(): void
    {
        $entity = new RfUser(5);

        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($this->locator);

        $this->locator->method("find")->willReturn($entity);

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::Equal]),
            new FilterRule(path: "user.id", operators: [FilterOperator::Equal]),
        ]);

        $result = $mapper->map(
            [
                "filter" => [
                    "equal" => ["user" => "5", "user.id" => "9"],
                ],
            ],
            "rfGarden",
        );

        $this->assertSame($entity, $result->filter->equal->user);
        $this->assertSame(9, $result->filter->equal->relUserRelId);
    }

    public function testRuleTypeSpecOverridesIdType(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        $entity = new RfUser(5);

        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($locator);

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(self::STUB_NS . "\\RfUser", "5")
            ->willReturn($entity);

        $mapper = $this->makeMapper([
            new FilterRule(
                path: "user",
                operators: [FilterOperator::Equal],
                typeSpec: $this->typeParser->parse("string"),
            ),
        ]);

        $result = $mapper->map(
            ["filter" => ["equal" => ["user" => "5"]]],
            "rfGarden",
        );

        $this->assertSame($entity, $result->filter->equal->user);
    }

    // ==================== Errors ====================
    //
    // ==================== Ошибки ====================

    public function testMissingEntityMakesFilterUnsatisfiable(): void
    {
        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($this->locator);

        $this->locator->method("find")->willReturn(null);

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::Equal]),
        ]);

        try {
            $mapper->map(["filter" => ["equal" => ["user" => "5"]]], "rfGarden");
            $this->fail("Expected UnsatisfiableListFilterException");
        } catch (UnsatisfiableListFilterException $unsatisfiableListFilterException) {
            $this->assertSame("rfUser", $unsatisfiableListFilterException->modelType);
            $this->assertSame("user", $unsatisfiableListFilterException->filterPath);
            $this->assertSame([5], $unsatisfiableListFilterException->missingIds);
        }
    }

    public function testInDropsMissingIdsAndKeepsFound(): void
    {
        $found = new RfUser(5);

        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($this->locator);

        $this->locator
            ->method("find")
            ->willReturnCallback(
                static fn (string $className, int|string $id): ?RfUser => $id === 5 ? $found : null,
            );

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::In]),
        ]);

        $result = $mapper->map(
            ["filter" => ["in" => ["user" => ["5", "404"]]]],
            "rfGarden",
        );

        $this->assertSame([$found], $result->filter->in->user);
    }

    public function testInWithOnlyMissingIdsIsUnsatisfiable(): void
    {
        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($this->locator);

        $this->locator->method("find")->willReturn(null);

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::In]),
        ]);

        $this->expectException(UnsatisfiableListFilterException::class);

        $mapper->map(["filter" => ["in" => ["user" => ["1", "2"]]]], "rfGarden");
    }

    public function testInUsesBatchLocatorOnce(): void
    {
        $first = new RfUser(5);
        $second = new RfUser(7);

        $batchLocator = $this->createMock(EntityBatchLocatorInterface::class);
        $batchLocator->expects($this->never())->method("find");
        $batchLocator
            ->expects($this->once())
            ->method("findMany")
            ->with(self::STUB_NS . "\\RfUser", [5, 7])
            ->willReturn([$first, $second]);

        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($batchLocator);

        $mapper = $this->makeMapper([
            new FilterRule(path: "user", operators: [FilterOperator::In]),
        ]);

        // Duplicate ids are collapsed before the locator is called
        //
        // Дубликаты id схлопываются до вызова локатора
        $result = $mapper->map(
            ["filter" => ["in" => ["user" => ["5", "7", "5"]]]],
            "rfGarden",
        );

        $this->assertSame([$first, $second], $result->filter->in->user);
    }

    public function testTargetModelWithoutReadLocatorIsMisconfiguration(): void
    {
        $this->locators
            ->method("getEntityLocatorByName")
            ->willReturn($this->locator);

        $mapper = $this->makeMapper(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            withUserReadLocator: false,
        );

        $this->expectException(ListActionMisconfiguredException::class);
        $this->expectExceptionMessage(
            "Model 'rfUser' has no entity locator required for relation filter",
        );

        $mapper->map(["filter" => ["equal" => ["user" => "5"]]], "rfGarden");
    }

    public function testMissingLocatorContainerIsMisconfiguration(): void
    {
        $mapper = $this->makeMapper(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            withLocatorContainer: false,
        );

        $this->expectException(ListActionMisconfiguredException::class);
        $this->expectExceptionMessage(
            "Filter by relation to model 'rfUser' requires locator container in MappingToListAction",
        );

        $mapper->map(["filter" => ["equal" => ["user" => "5"]]], "rfGarden");
    }

    public function testUndeclaredRelationPathIsRejected(): void
    {
        $mapper = $this->makeMapper([
            new FilterRule(
                path: "setupStatus",
                operators: [FilterOperator::Equal],
            ),
        ]);

        $this->expectException(InvalidListQueryException::class);
        $this->expectExceptionMessage("Unknown filter field 'user'");

        $mapper->map(["filter" => ["equal" => ["user" => "5"]]], "rfGarden");
    }

    // ==================== Model and default locator ====================
    //
    // ==================== Локатор модели и по умолчанию ====================

    /**
     * A filter by relation loads the entity with the model locator (impl.models.<model>.entityLocator)
     * without a read action; the model locator takes precedence over the read action locator.
     *
     * Фильтр по связи грузит сущность локатором модели (impl.models.<модель>.entityLocator)
     * без read-действия; локатор модели важнее локатора read-действия.
     */
    public function testRelationFilterUsesModelLocator(): void
    {
        foreach ([false, true] as $withRead) {
            $locators = $this->createMock(EntityLocatorContainerInterface::class);
            $locators->expects($this->once())
                ->method("getEntityLocatorByName")
                ->with("modelLoc")
                ->willReturn($this->locator);
            $this->locators = $locators;
            $this->locator->method("find")->willReturn(new RfUser(5));

            $mapper = $this->makeMapper(
                [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
                withUserReadLocator: $withRead,
                userModelLocator: "modelLoc",
                defaultLocator: "defaultLoc",
            );

            $mapper->map(["filter" => ["equal" => ["user" => "5"]]], "rfGarden");
        }
    }

    /**
     * Without a model locator and a read action — impl.defaultLocator.
     *
     * Без локатора модели и read-действия — impl.defaultLocator.
     */
    public function testRelationFilterUsesDefaultLocator(): void
    {
        $locators = $this->createMock(EntityLocatorContainerInterface::class);
        $this->locators = $locators;

        $locators->expects($this->once())
            ->method("getEntityLocatorByName")
            ->with("defaultLoc")
            ->willReturn($this->locator);
        $this->locator->method("find")->willReturn(new RfUser(5));

        $mapper = $this->makeMapper(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            withUserReadLocator: false,
            defaultLocator: "defaultLoc",
        );

        $mapper->map(["filter" => ["equal" => ["user" => "5"]]], "rfGarden");
    }
}
