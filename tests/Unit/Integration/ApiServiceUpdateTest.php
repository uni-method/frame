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
    RelationWriteMode,
};
use UniMethod\Frame\Config\WritableConfig;
use UniMethod\Frame\Integration\{
    ApiService,
    ConstraintCheckerInterface,
    ConstraintContext,
    ConstraintPhase,
    Error,
};
use UniMethod\Frame\Integration\{ApiResult, Errors};
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\ModelNotFoundException;
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{
    EntityLocatorInterface,
    EntitySaverInterface,
    ListLocators,
    ListRemovers,
    ListSavers,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Integration\Stub\{
    UpdateAddress,
    UpdateAuthor,
    UpdateBook,
    UpdatePublisher,
};
use UniMethod\Frame\Validator\ActionValidator;

class ApiServiceUpdateTest extends TestCase
{
    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    private ConstraintCheckerInterface&Stub $constraintChecker;

    private EntitySaverInterface&Stub $saver;

    private EntityLocatorInterface&Stub $locator;

    private Config $config;

    private MappingToObject&Stub $mappingToObject;

    private MappingFromObject&Stub $mappingFromObject;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\UpdateAddress",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;

                class UpdateAddress {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $city = null,
                        public ?string $street = null,
                        public ?string $zipCode = null,
                    ) {}
                }

                class UpdateBook {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $title = null,
                        public ?string $isbn = null,
                    ) {}
                }

                class UpdatePublisher {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                    ) {}
                }

                class UpdateAuthor {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        public ?string $email = null,
                        public ?string $bio = null,
                        public ?UpdateAddress $address = null,
                        public ?UpdatePublisher $publisher = null,
                        public array $books = [],
                    ) {}
                }
            ');
        }
    }

    private function service(): ApiService
    {
        return $this->service ??= ($this->serviceFactory)();
    }

    protected function setUp(): void
    {
        // Create stub objects
        //
        // Создаем stub-объекты
        $this->constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $this->saver = $this->createStub(EntitySaverInterface::class);
        $this->locator = $this->createStub(EntityLocatorInterface::class);
        $this->locator
            ->method("find")
            ->willReturnCallback(
                fn (string $class, string $id): object => new UpdateAuthor(id: $id),
            );
        $this->mappingToObject = $this->createStub(MappingToObject::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);

        // Create the configuration
        //
        // Создаем конфигурацию
        $this->config = new Config(
            models: [
                new Model(
                    class: UpdateAuthor::class,
                    type: "author",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                        new Attribute(name: "email", type: "string"),
                        new Attribute(name: "bio", type: "string"),
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
                        new Relation(
                            name: "publisher",
                            type: "manyToOne",
                            targetModel: "publisher",
                        ),
                    ],
                ),
                new Model(
                    class: UpdateAddress::class,
                    type: "address",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "city", type: "string"),
                        new Attribute(name: "street", type: "string"),
                        new Attribute(name: "zipCode", type: "string"),
                    ],
                ),
                new Model(
                    class: UpdateBook::class,
                    type: "book",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "title", type: "string"),
                        new Attribute(name: "isbn", type: "string"),
                    ],
                ),
                new Model(
                    class: UpdatePublisher::class,
                    type: "publisher",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Update,
                    model: "author",
                    writable: [
                        "address" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "books" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "publisher" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    include: ["address", "books", "publisher"],
                    entityLocator: "default",
                    entitySaver: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
            savers: ["default" => EntitySaverInterface::class],
        );

        // Set up the mapping
        //
        // Настраиваем маппинг
        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function (string $type, object $instance): \stdClass {
                return (object) [
                    "id" => $instance->id ?? "1",
                    "name" => $instance->name ?? "Test",
                ];
            });

        // Initialize the service
        //
        // Инициализируем сервис
        $this->serviceFactory = fn (): ApiService => new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: new ListSavers(["default" => $this->saver]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );
    }

    public function testSuccessfulUpdate(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $rawContent = json_encode(
            [
                "name" => "John Doe Updated",
                "email" => "john.updated@example.com",
            ],
            JSON_THROW_ON_ERROR
        );

        $entity = new UpdateAuthor(
            id: $id,
            name: "John Doe Updated",
            email: "john.updated@example.com",
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Update, "", $id)
            ->willReturn($entity);

        $constraintChecker
            ->expects($this->exactly(2))
            ->method("check")
            ->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->with($entity)
            ->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertTrue($result->encoded);
        $this->assertEmpty($result->headers);
    }

    /**
     * The found entity is passed by the mapper as the write target: the locator
     * is called once (ApiService), the mapper does not go to storage; original is
     * a clone of the entity before mapping.
     *
     * Найденная сущность передаётся маппером как цель записи: локатор
     * вызывается один раз (ApiService), маппер в хранилище не ходит; original —
     * clone сущности до маппинга.
     */
    public function testUpdatePassesLocatedEntityToMapperAndLocatesOnce(): void
    {
        $id = "123";
        $located = new UpdateAuthor(id: $id, name: "Before", email: "before@example.com");

        $locator = $this->createMock(EntityLocatorInterface::class);
        $locator
            ->expects($this->once())
            ->method("find")
            ->willReturn($located);

        $mappingToObject = $this->createMock(MappingToObject::class);
        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                ActionType::Update,
                "",
                $id,
                $this->anything(),
                $this->identicalTo($located),
            )
            ->willReturnCallback(static function () use ($located): object {
                $located->name = "After";

                return $located;
            });

        $snapshots = [];
        $mappingFromObject = $this->createStub(MappingFromObject::class);
        $mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $instance,
                ?array $include,
                ?ActionType $actionType,
            ) use (&$snapshots): object {
                $snapshots[] = [$instance->name, $actionType];

                return (object) ["id" => $instance->id, "name" => $instance->name];
            });

        $originals = [];
        $constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $constraintChecker
            ->method("check")
            ->willReturnCallback(static function (object $object, ConstraintContext $context) use (&$originals): array {
                $originals[] = $context->original;

                return [];
            });

        $saver = $this->createStub(EntitySaverInterface::class);
        $saver->method("save")->willReturnArgument(0);

        $service = new ApiService(
            constraintChecker: $constraintChecker,
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $mappingFromObject,
            mappingToObject: $mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $locator]),
            savers: new ListSavers(["default" => $saver]),
            removers: new ListRemovers([]),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: '{"name": "After"}',
            id: $id,
        );

        $this->assertSame(200, $result->statusCode);
        // MappingFromObject — only for the response, after mapping
        //
        // MappingFromObject — только для ответа, после маппинга
        $this->assertSame([["After", ActionType::Update]], $snapshots);
        // AfterLocate — without original, AfterInput — a clone with the "before" value
        //
        // AfterLocate — без original, AfterInput — clone со значением «до»
        $this->assertNull($originals[0]);
        $this->assertInstanceOf(UpdateAuthor::class, $originals[1]);
        $this->assertNotSame($located, $originals[1]);
        $this->assertSame("Before", $originals[1]->name);
    }

    public function testUpdateWithoutId(): void
    {
        // Arrange
        //
        // Подготовка
        $rawContent = json_encode(["name" => "John Doe"], JSON_THROW_ON_ERROR);

        // Act - do not pass the ID
        //
        // Act - не передаем ID
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testUpdateWithPartialData(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;

        // Arrange - update only one field
        //
        // Arrange - обновляем только одно поле
        $id = "123";
        $rawContent = json_encode(["name" => "New Name Only"], JSON_THROW_ON_ERROR);

        $entity = new UpdateAuthor(
            id: $id,
            name: "New Name Only",
            email: "old@example.com",
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Update, "", $id)
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertTrue($result->encoded);
    }

    public function testUpdateWithValidationErrors(): void
    {
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $rawContent = json_encode(
            [
                // Invalid
                //
                // Невалидно
                "name" => "",
                // Invalid
                //
                // Невалидно
                "email" => "not-an-email",
            ],
            JSON_THROW_ON_ERROR
        );

        $entity = new UpdateAuthor(id: $id, name: "", email: "not-an-email");

        $this->mappingToObject->method("map")->willReturn($entity);

        $validationErrors = [
            new Error(422, "Name cannot be empty", source: ["name"]),
            new Error(422, "Invalid email format", source: ["email"]),
        ];

        $constraintChecker
            ->expects($this->exactly(2))
            ->method("check")
            ->willReturnCallback(
                fn (object $object, ConstraintContext $context): array =>
                    $context->phase === ConstraintPhase::AfterInput
                        ? $validationErrors
                        : [],
            );

        $saver->expects($this->never())->method("save");

        // Act
        //
        // Действие
        /** @var ApiResult<Errors> $result */
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(422, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testUpdateActionNotFound(): void
    {
        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "nonexistent",
            actionType: ActionType::Update,
            rawContent: "{}",
            id: "123",
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testUpdateModelNotFound(): void
    {
        // Arrange
        //
        // Подготовка
        $configWithBadAction = new Config(
            models: $this->config->models,
            actions: [
                new Action(
                    type: ActionType::Update,
                    model: "nonexistent_model",
                ),
            ],
        );

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $configWithBadAction,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: new ListSavers(["default" => $this->saver]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(
            "Model not found: nonexistent_model",
        );

        $service->execute(
            modelType: "nonexistent_model",
            actionType: ActionType::Update,
            rawContent: "{}",
            id: "123",
        );
    }

    public function testUpdateWithInvalidJson(): void
    {
        // Arrange
        //
        // Подготовка
        $invalidJson = "{invalid: json}";

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $invalidJson,
            id: "123",
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testUpdateWithNestedRelationCreate(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;

        // Arrange - update with creating a nested address
        //
        // Arrange - обновление с созданием вложенного адреса
        $id = "123";
        $rawContent = json_encode(
            [
                "name" => "John Doe",
                "address" => [
                    "city" => "New York",
                    "street" => "5th Avenue",
                    "zipCode" => "10001",
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $address = new UpdateAddress(
            id: "1",
            city: "New York",
            street: "5th Avenue",
            zipCode: "10001",
        );
        $entity = new UpdateAuthor(
            id: $id,
            name: "John Doe",
            address: $address,
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Update, "", $id)
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertTrue($result->encoded);
    }

    public function testUpdateWithNestedRelationReference(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;

        // Arrange - update with a reference to an existing publisher
        //
        // Arrange - обновление со ссылкой на существующего publisher
        $id = "123";
        $rawContent = json_encode(
            [
                "name" => "John Doe",
                // Reference to an existing one
                //
                // Ссылка на существующего
                "publisher" => ["id" => "456"],
            ],
            JSON_THROW_ON_ERROR
        );

        $publisher = new UpdatePublisher(
            id: "456",
            name: "Big UpdatePublisher",
        );
        $entity = new UpdateAuthor(
            id: $id,
            name: "John Doe",
            publisher: $publisher,
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Update, "", $id)
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateWithOneToManyRelation(): void
    {
        // Arrange - update with a collection of books
        //
        // Arrange - обновление с коллекцией книг
        $id = "123";
        $rawContent = json_encode(
            [
                "name" => "John Doe",
                "books" => [
                    ["title" => "UpdateBook 1", "isbn" => "1234567890"],
                    ["title" => "UpdateBook 2", "isbn" => "0987654321"],
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $book1 = new UpdateBook(
            id: "1",
            title: "UpdateBook 1",
            isbn: "1234567890",
        );
        $book2 = new UpdateBook(
            id: "2",
            title: "UpdateBook 2",
            isbn: "0987654321",
        );
        $entity = new UpdateAuthor(
            id: $id,
            name: "John Doe",
            books: [$book1, $book2],
        );

        $this->mappingToObject->method("map")->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateWithEmptyBody(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;

        // Arrange - update with an empty body (technically valid)
        //
        // Arrange - обновление с пустым телом (технически валидно)
        $id = "123";
        $entity = new UpdateAuthor(id: $id, name: "Existing Name");

        $mappingToObject
            ->method("map")
            ->with("author", $this->anything(), ActionType::Update, "", $id)
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: "{}",
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateWithNullContent(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $entity = new UpdateAuthor(id: $id, name: "Existing Name");

        $this->mappingToObject->method("map")->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act - rawContent = null is converted to '{}'
        //
        // Act - rawContent = null преобразуется в '{}'
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateWithIncludeParameter(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $rawContent = json_encode(["name" => "John Doe"], JSON_THROW_ON_ERROR);
        $entity = new UpdateAuthor(id: $id, name: "John Doe");

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                ActionType::Update,
                "address,books",
                $id,
            )
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            queryParams: ["included" => "address,books"],
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateWithNestedInclude(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;

        // Arrange - include with dot notation
        //
        // Arrange - include с точечной нотацией
        $id = "123";
        $rawContent = json_encode(["name" => "John Doe"], JSON_THROW_ON_ERROR);
        $entity = new UpdateAuthor(id: $id, name: "John Doe");

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                ActionType::Update,
                "address.city,books.title",
                $id,
            )
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            queryParams: ["included" => "address.city,books.title"],
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateWithComplexValidationErrors(): void
    {
        // Arrange - multiple errors at different levels
        //
        // Arrange - множественные ошибки на разных уровнях
        $id = "123";
        $rawContent = json_encode(
            [
                // Too short
                //
                // Слишком короткое
                "name" => "AB",
                "email" => "invalid",
                "address" => [
                    // Empty city
                    //
                    // Пустой город
                    "city" => "",
                    // Invalid format
                    //
                    // Неверный формат
                    "zipCode" => "123",
                ],
            ],
            JSON_THROW_ON_ERROR
        );

        $entity = new UpdateAuthor(id: $id, name: "AB", email: "invalid");

        $this->mappingToObject->method("map")->willReturn($entity);

        $validationErrors = [
            new Error(
                422,
                "Name must be at least 3 characters",
                source: ["name"],
            ),
            new Error(422, "Invalid email format", source: ["email"]),
            new Error(422, "City is required", source: ["address", "city"]),
            new Error(
                422,
                "ZIP code must be 5 digits",
                source: ["address", "zipCode"],
            ),
        ];

        $this->constraintChecker->method("check")->willReturn($validationErrors);

        // Act
        //
        // Действие
        /** @var ApiResult<Errors> $result */
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(422, $result->statusCode);
        $this->assertObjectHasProperty("errors", $result->data);
        $this->assertCount(4, $result->data->errors);
    }

    public function testUpdateReturnsCorrectStatusCode(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $rawContent = json_encode(["name" => "Updated"], JSON_THROW_ON_ERROR);
        $entity = new UpdateAuthor(id: $id, name: "Updated");

        $this->mappingToObject->method("map")->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        // Update returns 200, not 201
        //
        // Update возвращает 200, не 201
        $this->assertEquals(200, $result->statusCode);
        $this->assertNotEquals(201, $result->statusCode);
    }

    public function testUpdateWithMixedValidationErrorCodes(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $rawContent = json_encode(["name" => "Test"], JSON_THROW_ON_ERROR);
        $entity = new UpdateAuthor(id: $id, name: "Test");

        $this->mappingToObject->method("map")->willReturn($entity);

        // Different error codes - fallback to 400
        //
        // Разные коды ошибок - fallback на 400
        $validationErrors = [
            new Error(422, "Validation error"),
            new Error(409, "Conflict: email already exists"),
            new Error(403, "Forbidden field"),
        ];

        $this->constraintChecker->method("check")->willReturn($validationErrors);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        // Default for mixed codes
        //
        // Default для смешанных кодов
        $this->assertEquals(400, $result->statusCode);
    }

    public function testUpdateWithAllRelationTypes(): void
    {
        // Arrange - update with all relation types at once
        //
        // Arrange - обновление со всеми типами связей одновременно
        $id = "123";
        $rawContent = json_encode(
            [
                "name" => "John Doe",
                "address" => ["city" => "NYC"], // oneToOne
                "publisher" => ["id" => "999"], // manyToOne
                "books" => [["title" => "UpdateBook 1"]], // oneToMany
            ],
            JSON_THROW_ON_ERROR
        );

        $address = new UpdateAddress(id: "1", city: "NYC");
        $publisher = new UpdatePublisher(id: "999", name: "UpdatePublisher");
        $book = new UpdateBook(id: "1", title: "UpdateBook 1");
        $entity = new UpdateAuthor(
            id: $id,
            name: "John Doe",
            address: $address,
            publisher: $publisher,
            books: [$book],
        );

        $this->mappingToObject->method("map")->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testUpdateIdPassedToMapping(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;

        // Arrange - check that the ID is passed to the mapping
        //
        // Arrange - проверяем что ID передается в маппинг
        $id = "123";
        $rawContent = json_encode(["name" => "Test"], JSON_THROW_ON_ERROR);
        $entity = new UpdateAuthor(id: $id, name: "Test");

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                ActionType::Update,
                "",
                // Check that the ID was passed
                //
                // Проверяем что ID передан
                $id,
            )
            ->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Update,
            rawContent: $rawContent,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }
}
