<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

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
use UniMethod\Frame\Config\AccessConfig;
use UniMethod\Frame\Config\ListAction\{
    DefaultSortValue,
    FilterDefaultValue,
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
    PaginationRule,
    SortDirection,
    SortRule,
};
use UniMethod\Frame\Integration\{ApiService, ConstraintCheckerInterface, Error};
use UniMethod\Frame\Integration\Exception\AmbiguousRepositoryException;
use UniMethod\Frame\Integration\{UserInterface, UserProviderInterface};
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\ModelNotFoundException;
use UniMethod\Frame\Mapping\ListQuery\Exception\{
    ListActionMisconfiguredException,
    UnsatisfiableListFilterException,
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{ListLocators, ListRemovers, ListSavers};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Integration\Stub\{ListAuthor, ListListActionResult};
use UniMethod\Frame\Tests\Unit\Stub\RecordingRepository;
use UniMethod\Frame\Type\TypeSpecParser;
use UniMethod\Frame\Validator\ActionValidator;

class ApiServiceListTest extends TestCase
{
    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    private ConstraintCheckerInterface&Stub $constraintChecker;

    private RecordingRepository $repository;

    private MappingToListAction&Stub $mappingToListAction;

    private MappingFromObject&Stub $mappingFromObject;

    private Config $config;

    private UserProviderInterface&Stub $userProvider;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\ListAuthor",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;
                class ListAuthor {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        public ?string $email = null,
                        public ?\DateTime $createdAt = null,
                    ) {}
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\ListListActionResult",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;
                class ListListActionResult {
                    public function __construct(
                        public ?object $filter = null,
                        public ?object $sort = null,
                        public ?object $pagination = null,
                    ) {}
                }
            ');
        }
    }

    private function service(): ApiService
    {
        return $this->service ??= ($this->serviceFactory)();
    }

    private function stubMappingFromObject(MappingFromObject&Stub $mappingFromObject): void
    {
        $mappingFromObject->method("map")->willReturnCallback(
            fn ($type, $instance): \stdClass => (object) [
                "id" => $instance->id,
                "name" => $instance->name,
            ],
        );
    }

    protected function setUp(): void
    {
        $this->constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $this->repository = new RecordingRepository();
        $this->mappingToListAction = $this->createStub(
            MappingToListAction::class,
        );
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);
        $this->userProvider = $this->createStub(UserProviderInterface::class);

        $this->config = new Config(
            models: [
                new Model(
                    class: ListAuthor::class,
                    type: "author",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                        new Attribute(name: "email", type: "string"),
                        new Attribute(name: "createdAt", type: "datetime"),
                    ],
                    relations: [
                        new Relation(
                            name: "address",
                            type: "oneToOne",
                            targetModel: "address",
                        ),
                        new Relation(
                            name: "books",
                            type: "oneToMany",
                            targetModel: "book",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::List,
                    model: "author",
                    include: ["address"],
                    config: new ListActionConfig(
                        class: ListListActionResult::class,
                        repository: RecordingRepository::class,
                        pagination: new PaginationRule(
                            maxSize: 100,
                            defaultSize: 20,
                        ),
                        sort: [
                            new SortRule(
                                path: "name",
                                defaultDirection: SortDirection::Asc,
                            ),
                            new SortRule(
                                path: "createdAt",
                                defaultValue: new DefaultSortValue(
                                    "createdAt",
                                    SortDirection::Desc,
                                ),
                            ),
                        ],
                        filters: [
                            new FilterRule(
                                path: "name",
                                operators: [
                                    FilterOperator::Equal,
                                    FilterOperator::Like,
                                ],
                            ),
                            new FilterRule(
                                path: "email",
                                operators: [FilterOperator::Equal],
                            ),
                            new FilterRule(
                                path: "status",
                                operators: [FilterOperator::In],
                                required: true,
                                defaultValue: new FilterDefaultValue(
                                    FilterOperator::In,
                                    ["active"],
                                ),
                            ),
                        ],
                    ),
                ),
            ],
        );

        $this->stubMappingFromObject($this->mappingFromObject);

        $this->serviceFactory = fn (): ApiService => new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            repositories: [$this->repository],
        );
    }

    public function testSuccessfulList(): void
    {
        $listResult = new ListListActionResult(
            filter: null,
            sort: null,
            pagination: (object) ["page" => 1, "size" => 20],
        );

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
            new ListAuthor(id: "2", name: "Jane"),
        ];
        $this->repository->returnCount = 2;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsArray($result->data);
        $this->assertCount(2, $result->data);
        $this->assertEquals("2", $result->headers["X-Total-Count"]);
    }

    public function testListWithFilter(): void
    {
        $filterObj = (object) [
            "name" => ["operator" => "equal", "value" => "John"],
        ];
        $listResult = new ListListActionResult(filter: $filterObj);

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["filter" => ["name" => "John"]],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertIsArray($result->data);
        $this->assertCount(1, $result->data);
        $this->assertEquals("1", $result->headers["X-Total-Count"]);
        $this->assertEquals(
            $filterObj,
            $this->repository->listCalls[0]["filter"],
        );
    }

    public function testListWithSort(): void
    {
        $sortObj = (object) ["name" => "asc", "createdAt" => "desc"];
        $listResult = new ListListActionResult(sort: $sortObj);

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "Alice"),
            new ListAuthor(id: "2", name: "Bob"),
        ];
        $this->repository->returnCount = 2;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["sort" => ["name" => "asc"]],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertCount(2, (array) $result->data);
        $this->assertEquals($sortObj, $this->repository->listCalls[0]["sort"]);
    }

    public function testListWithPagination(): void
    {
        $paginationObj = (object) ["page" => 2, "size" => 10];
        $listResult = new ListListActionResult(pagination: $paginationObj);

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "11", name: "Author 11"),
        ];
        $this->repository->returnCount = 25;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["page" => 2, "size" => 10],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertEquals("25", $result->headers["X-Total-Count"]);
        $this->assertEquals(
            $paginationObj,
            $this->repository->listCalls[0]["pagination"],
        );
    }

    public function testListWithAllParameters(): void
    {
        $filterObj = (object) [
            "status" => ["operator" => "in", "value" => ["active"]],
        ];
        $sortObj = (object) ["name" => "asc"];
        $paginationObj = (object) ["page" => 1, "size" => 20];

        $listResult = new ListListActionResult(
            filter: $filterObj,
            sort: $sortObj,
            pagination: $paginationObj,
        );

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => ["status" => ["active"]],
                "sort" => ["name" => "asc"],
                "page" => 1,
                "size" => 20,
            ],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertIsArray($result->data);
        $this->assertCount(1, $result->data);
        $this->assertEquals(
            $filterObj,
            $this->repository->listCalls[0]["filter"],
        );
        $this->assertEquals($sortObj, $this->repository->listCalls[0]["sort"]);
        $this->assertEquals(
            $paginationObj,
            $this->repository->listCalls[0]["pagination"],
        );
    }

    public function testListWithInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $mappingFromObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                $this->callback(
                    fn ($include): bool => is_array($include) &&
                        in_array("address", $include, true),
                ),
            );

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertEquals(200, $result->statusCode);
    }

    public function testListWithCustomInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $mappingFromObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                $this->callback(
                    fn ($include): bool => is_array($include) &&
                        in_array("address", $include, true) &&
                        in_array("books", $include, true),
                ),
            );

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["included" => "books"],
        );

        $this->assertEquals(200, $result->statusCode);
    }

    /**
     * The repository receives the resulting list of relation paths — the action's `include`
     * ∪ the paths requested via `included` that passed the `includable` filter —
     * in the same flat representation as EntityLocatorInterface::find.
     *
     * Репозиторий получает итоговый список путей связей — `include` действия
     * ∪ запрошенные через `included` пути, прошедшие фильтр `includable`, —
     * в том же плоском представлении, что и EntityLocatorInterface::find.
     */
    public function testIncludedIsPassedToRepository(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: ListAuthor::class,
                    type: "post",
                    attributes: [new Attribute(name: "id", type: "string")],
                    relations: [
                        new Relation(name: "author", type: "manyToOne", targetModel: "author"),
                        new Relation(name: "tags", type: "manyToMany", targetModel: "tag"),
                        new Relation(name: "editor", type: "manyToOne", targetModel: "author"),
                        new Relation(name: "secret", type: "oneToOne", targetModel: "secret"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::List,
                    model: "post",
                    include: ["editor"],
                    includable: ["author" => null, "tags" => null],
                    config: new ListActionConfig(
                        repository: RecordingRepository::class,
                    ),
                ),
            ],
        );
        $service = $this->buildServiceWithUserProvider($config);

        $this->mappingToListAction->method("map")->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);

        $result = $service->execute(
            modelType: "post",
            actionType: ActionType::List,
            queryParams: ["included" => "author,tags,secret"],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertCount(1, $this->repository->listCalls);
        $this->assertSame(
            ["editor", "author", "tags"],
            $this->repository->listCalls[0]["included"],
        );
    }

    public function testListEmptyResult(): void
    {
        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertIsArray($result->data);
        $this->assertEmpty($result->data);
        $this->assertEquals("0", $result->headers["X-Total-Count"]);
    }

    /**
     * A filter by relation with a non-existent id is a knowingly empty selection:
     * 200, an empty list, X-Total-Count 0, the repository is not called.
     *
     * Фильтр по связи с несуществующим id — заведомо пустая выборка:
     * 200, пустой список, X-Total-Count 0, репозиторий не вызывается.
     */
    public function testUnsatisfiableRelationFilterReturnsEmptyListWithoutRepository(): void
    {
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $this->mappingToListAction
            ->method("map")
            ->willThrowException(
                new UnsatisfiableListFilterException("author", "company", [404]),
            );
        $constraintChecker->expects($this->never())->method("check");

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["filter" => ["equal" => ["company" => "404"]]],
        );

        $this->assertSame(200, $result->statusCode);
        $this->assertSame([], $result->data);
        $this->assertSame(["X-Total-Count" => "0"], $result->headers);
        $this->assertSame([], $this->repository->listCalls);
        $this->assertSame([], $this->repository->countCalls);
    }

    public function testListWithValidationErrors(): void
    {
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);

        $validationErrors = [
            new Error(422, "Invalid filter value"),
            new Error(422, "Sort field not allowed"),
        ];

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with($listResult)
            ->willReturn($validationErrors);

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["filter" => ["invalid" => "value"]],
        );

        $this->assertEquals(422, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", (object) $result->data);
        $this->assertCount(0, $this->repository->listCalls);
    }

    /**
     * The repository is matched to `config.repository` by instanceof: DI may
     * return a lazy proxy or a subclass, get_class will not match it.
     *
     * Репозиторий сопоставляется с `config.repository` по instanceof: DI может
     * отдать lazy-прокси или наследника, get_class с ним не совпадёт.
     */
    public function testRepositoryResolvedByInstanceof(): void
    {
        $proxy = new class () extends RecordingRepository {
        };
        $proxy->returnItems = [new ListAuthor(id: "1", name: "Alice")];
        $proxy->returnCount = 1;

        $this->mappingToListAction->method("map")->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);

        $service = $this->serviceWithRepositories([$proxy]);

        $result = $service->execute(modelType: "author", actionType: ActionType::List);

        $this->assertSame(200, $result->statusCode);
        $this->assertCount(1, (array) $result->data);
        $this->assertCount(1, $proxy->listCalls);
    }

    public function testTwoRepositoriesOfSameClassIsConfigurationError(): void
    {
        $this->expectException(AmbiguousRepositoryException::class);
        $this->expectExceptionMessage("List action of model 'author' expects one repository of");

        // An error at construction, not on the first request
        //
        // Ошибка на конструировании, не на первом запросе
        $this->serviceWithRepositories([$this->repository, new RecordingRepository()]);
    }

    /**
     * @param list<object> $repositories
     */
    private function serviceWithRepositories(array $repositories): ApiService
    {
        return new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            repositories: $repositories,
        );
    }

    public function testListRepositoryNotConfigured(): void
    {
        $configWithoutRepo = new Config(
            models: $this->config->models,
            actions: [
                new Action(
                    type: ActionType::List,
                    model: "author",
                    config: new ListActionConfig(),
                ),
            ],
        );

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $configWithoutRepo,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $this->mappingToListAction
            ->method("map")
            ->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);

        $this->expectException(ListActionMisconfiguredException::class);
        $this->expectExceptionMessage(
            "Repository is not configured for list action of model 'author'",
        );

        $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );
    }

    public function testListRepositoryNotImplementsInterface(): void
    {
        $invalidRepo = new class () {
        };

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            repositories: [$invalidRepo],
        );

        $this->mappingToListAction
            ->method("map")
            ->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);

        $this->expectException(ListActionMisconfiguredException::class);
        $this->expectExceptionMessage(
            "Repository 'UniMethod\\Frame\\Tests\\Unit\\Stub\\RecordingRepository' for list action of model 'author' " .
            "is not passed to ApiService",
        );

        $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );
    }

    public function testListActionNotFound(): void
    {
        $result = $this->service()->execute(
            modelType: "nonexistent",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertEquals(404, $result->statusCode);
    }

    public function testListModelNotFound(): void
    {
        $configWithBadAction = new Config(
            models: $this->config->models,
            actions: [
                new Action(type: ActionType::List, model: "nonexistent_model"),
            ],
        );

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $configWithBadAction,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            repositories: [$this->repository],
        );

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(
            "Model not found: nonexistent_model",
        );

        $service->execute(
            modelType: "nonexistent_model",
            actionType: ActionType::List,
            queryParams: [],
        );
    }

    public function testListReturnsArrayNotJson(): void
    {
        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertFalse($result->encoded);
        $this->assertIsArray($result->data);
    }

    public function testListCountForListUsesFilter(): void
    {
        $filterObj = (object) [
            "name" => ["operator" => "like", "value" => "%John%"],
        ];
        $listResult = new ListListActionResult(filter: $filterObj);

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["filter" => ["name" => "John"]],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertEquals(
            $filterObj,
            $this->repository->countCalls[0]["filter"],
        );
    }

    public function testListMapsEachItem(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
            new ListAuthor(id: "2", name: "Jane"),
            new ListAuthor(id: "3", name: "Bob"),
        ];
        $this->repository->returnCount = 3;

        $mappingFromObject
            ->expects($this->exactly(3))
            ->method("map")
            ->willReturnCallback(
                fn ($type, $author): \stdClass => (object) ["id" => $author->id],
            );

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertIsArray($result->data);
        $this->assertCount(3, $result->data);
    }

    public function testListXTotalCountHeader(): void
    {
        $listResult = new ListListActionResult();

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "Author 1"),
            new ListAuthor(id: "2", name: "Author 2"),
        ];
        $this->repository->returnCount = 150;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertArrayHasKey("X-Total-Count", $result->headers);
        $this->assertEquals("150", $result->headers["X-Total-Count"]);
        $this->assertIsString($result->headers["X-Total-Count"]);
    }

    public function testListWithMultipleFilters(): void
    {
        $filterObj = (object) [
            "name" => ["operator" => "like", "value" => "%John%"],
            "email" => ["operator" => "equal", "value" => "john@example.com"],
            "status" => ["operator" => "in", "value" => ["active", "pending"]],
        ];

        $listResult = new ListListActionResult(filter: $filterObj);

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => [
                    "name" => "John",
                    "email" => "john@example.com",
                    "status" => ["active", "pending"],
                ],
            ],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertEquals(
            $filterObj,
            $this->repository->listCalls[0]["filter"],
        );
    }

    public function testListMappingToListActionReceivesCorrectParams(): void
    {
        $mappingToListAction = $this->createMock(MappingToListAction::class);
        $this->mappingToListAction = $mappingToListAction;

        $queryParams = [
            "filter" => ["name" => "John"],
            "sort" => ["createdAt" => "desc"],
            "page" => 2,
            "size" => 50,
        ];

        $mappingToListAction
            ->expects($this->once())
            ->method("map")
            ->with($queryParams, "author")
            ->willReturn(new ListListActionResult());

        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: $queryParams,
        );

        $this->assertEquals(200, $result->statusCode);
    }

    public function testListRepositoryCalledBeforeMapping(): void
    {
        $listResult = new ListListActionResult();
        $callOrder = [];

        $this->mappingToListAction->method("map")->willReturn($listResult);
        $this->constraintChecker->method("check")->willReturn([]);

        $this->repository->onList = function () use (&$callOrder): void {
            $callOrder[] = "list";
        };

        $this->repository->onCountForList = function () use (
            &$callOrder,
        ): void {
            $callOrder[] = "countForList";
        };

        $this->repository->returnItems = [
            new ListAuthor(id: "1", name: "John"),
        ];
        $this->repository->returnCount = 1;

        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function () use (&$callOrder): \stdClass {
                $callOrder[] = "map";
                return (object) ["id" => "1"];
            });

        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertEquals(["list", "countForList", "map"], $callOrder);
    }

    // ==================== FilterOperatorConfig Access Tests ====================
    //
    // ==================== Тесты доступа FilterOperatorConfig ====================

    /**
     * Query parsing lives in MappingToListAction::parseFilters(); the mapper mock
     * delegates it to a real instance with the same config so that the access check
     * on operators in ApiService sees real ParsedFilter objects.
     *
     * Разбор query живёт в MappingToListAction::parseFilters(); мок маппера
     * делегирует его реальному экземпляру с тем же конфигом, чтобы проверка
     * access на операторах в ApiService видела настоящие ParsedFilter.
     */
    private function delegateParseFilters(Config $config): void
    {
        $real = new MappingToListAction(new TypeSpecParser(), config: $config);
        $this->mappingToListAction
            ->method("parseFilters")
            ->willReturnCallback(
                static fn (array $params, string $modelType): array => $real->parseFilters($params, $modelType),
            );
    }

    private function buildServiceWithUserProvider(Config $config): ApiService
    {
        $this->delegateParseFilters($config);

        return new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $this->userProvider,
            repositories: [$this->repository],
        );
    }

    private function buildConfigWithOperatorAccess(AccessConfig $access): Config
    {
        return new Config(
            models: $this->config->models,
            actions: [
                new Action(
                    type: ActionType::List,
                    model: "author",
                    config: new ListActionConfig(
                        repository: RecordingRepository::class,
                        filters: [
                            new FilterRule(
                                path: "status",
                                operators: [
                                    FilterOperator::Equal,
                                    new FilterOperatorConfig(
                                        FilterOperator::In,
                                        $access,
                                    ),
                                ],
                            ),
                        ],
                    ),
                ),
            ],
        );
    }

    /**
     * An operator in the extended form `{ operator, access }` is checked on the standard
     * query format `filter[<operator>][<path>]`: a denial — a response with an error,
     * the repository is not called.
     *
     * Оператор в расширенной форме `{ operator, access }` проверяется на штатном
     * формате query `filter[<operator>][<path>]`: отказ — ответ с ошибкой,
     * репозиторий не вызывается.
     */
    public function testFilterOperatorAccessDeniedReturns403(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]);
        $config = $this->buildConfigWithOperatorAccess($access);
        $service = $this->buildServiceWithUserProvider($config);

        $userProvider
            ->method("getCurrentUser")
            ->willReturn($this->createStub(UserInterface::class));
        $userProvider
            ->method("canAccess")
            ->with($access)
            ->willReturn(false);

        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => ["in" => ["status" => ["active"]]],
            ],
        );

        $this->assertEquals(403, $result->statusCode);
        $this->assertCount(0, $this->repository->listCalls);
    }

    public function testFilterOperatorAccessGrantedProceeds(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]);
        $config = $this->buildConfigWithOperatorAccess($access);
        $service = $this->buildServiceWithUserProvider($config);

        $userProvider
            ->method("canAccess")
            ->with($access)
            ->willReturn(true);

        $this->mappingToListAction
            ->method("map")
            ->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);
        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => ["in" => ["status" => ["active"]]],
            ],
        );

        $this->assertEquals(200, $result->statusCode);
        $this->assertCount(1, $this->repository->listCalls);
    }

    public function testFilterOperatorWithoutAccessConfigAlwaysAllowed(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]);
        $config = $this->buildConfigWithOperatorAccess($access);
        $service = $this->buildServiceWithUserProvider($config);

        $userProvider->expects($this->never())->method("canAccess");

        $this->mappingToListAction
            ->method("map")
            ->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);
        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        // "equal" — a plain FilterOperator, no AccessConfig
        //
        // "equal" — plain FilterOperator, нет AccessConfig
        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => ["equal" => ["status" => "active"]],
            ],
        );

        $this->assertEquals(200, $result->statusCode);
    }

    public function testFilterOperatorAccessDeniedWhenNoUserProvider(): void
    {
        $access = new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]);
        $config = $this->buildConfigWithOperatorAccess($access);

        // Service without userProvider
        //
        // Сервис без userProvider
        $this->delegateParseFilters($config);
        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            repositories: [$this->repository],
        );

        $this->mappingToListAction
            ->method("map")
            ->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);
        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => ["in" => ["status" => ["active"]]],
            ],
        );

        $this->assertEquals(401, $result->statusCode);
        $this->assertCount(0, $this->repository->listCalls);
    }

    public function testNonOperatorFilterKeyIsRejectedBeforeAccessCheck(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["ROLE_ADMIN"]);
        $config = $this->buildConfigWithOperatorAccess($access);
        $service = $this->buildServiceWithUserProvider($config);

        $userProvider->expects($this->never())->method("canAccess");

        $this->mappingToListAction
            ->method("map")
            ->willReturn(new ListListActionResult());
        $this->constraintChecker->method("check")->willReturn([]);
        $this->repository->returnItems = [];
        $this->repository->returnCount = 0;

        // filter[status]: "status" is not an operator. Parsing is shared by all:
        // parseFilters gives 400 even before the access check, canAccess is not called
        //
        // filter[status]: "status" — не оператор. Разбор один на всех:
        // parseFilters даёт 400 ещё до проверки access, canAccess не вызывается
        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: ["filter" => ["status" => ["active"]]],
        );

        $this->assertEquals(400, $result->statusCode);
        $this->assertCount(0, $this->repository->listCalls);
    }

    public function testFilterOperatorAccessDeniedMessageContainsOperatorAndPath(): void
    {
        $access = new AccessConfig(authRequired: true);
        $config = $this->buildConfigWithOperatorAccess($access);
        $service = $this->buildServiceWithUserProvider($config);

        $this->userProvider->method("canAccess")->willReturn(false);

        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::List,
            queryParams: [
                "filter" => ["in" => ["status" => ["active"]]],
            ],
        );

        $this->assertEquals(401, $result->statusCode);
        $encoded = json_encode($result->data, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString("in", $encoded);
        $this->assertStringContainsString("status", $encoded);
    }
}
