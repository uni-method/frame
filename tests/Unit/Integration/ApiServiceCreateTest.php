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
use UniMethod\Frame\Integration\{ApiResult, Errors};
use UniMethod\Frame\Integration\{ApiService, ConstraintCheckerInterface, Error};
use UniMethod\Frame\Integration\Exception\SaverContractViolationException;
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\ModelNotFoundException;
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{
    EntitySaverInterface,
    ListLocators,
    ListRemovers,
    ListSavers,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Integration\Stub\{Address, Author, Book};
use UniMethod\Frame\Validator\ActionValidator;

class ApiServiceCreateTest extends TestCase
{
    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    private ConstraintCheckerInterface&Stub $constraintChecker;

    private EntitySaverInterface&Stub $saver;

    private Config $config;

    private MappingToObject&Stub $mappingToObject;

    private MappingFromObject&Stub $mappingFromObject;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Integration\Stub\Address")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;

                class Address {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $city = null,
                        public ?string $street = null,
                    ) {}
                }

                class Book {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $title = null,
                    ) {}
                }

                class Author {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        public ?string $email = null,
                        public ?Address $address = null,
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
        $this->mappingToObject = $this->createStub(MappingToObject::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);

        // Create the configuration
        //
        // Создаем конфигурацию
        $this->config = new Config(
            models: [
                new Model(
                    class: Author::class,
                    type: "author",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                        new Attribute(name: "email", type: "string"),
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
                new Model(
                    class: Address::class,
                    type: "address",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "city", type: "string"),
                        new Attribute(name: "street", type: "string"),
                    ],
                ),
                new Model(
                    class: Book::class,
                    type: "book",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "title", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "author",
                    writable: [
                        "address" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "books" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                    include: ["address", "books"],
                    entitySaver: "default",
                ),
            ],
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
            locators: $this->createStub(ListLocators::class),
            savers: new ListSavers(["default" => $this->saver]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );
    }

    public function testSuccessfulCreate(): void
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
        $rawContent = json_encode(
            [
                "name" => "John Doe",
                "email" => "john@example.com",
            ],
            JSON_THROW_ON_ERROR,
        );

        $entity = new Author(
            id: "1",
            name: "John Doe",
            email: "john@example.com",
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Create, "", null)
            ->willReturn($entity);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with($entity)
            // No validation errors
            //
            // Нет ошибок валидации
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
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(201, $result->statusCode);
        $this->assertTrue($result->encoded);
        $this->assertEmpty($result->headers);
    }

    public function testCreateWithValidationErrors(): void
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
        $rawContent = json_encode(
            [
                // Invalid: empty name
                //
                // Невалидно: пустое имя
                "name" => "",
                // Invalid email
                //
                // Невалидный email
                "email" => "invalid-email",
            ],
            JSON_THROW_ON_ERROR,
        );

        $entity = new Author(id: null, name: "", email: "invalid-email");

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->willReturn($entity);

        $validationErrors = [
            new Error(422, "Name cannot be empty", source: ["name"]),
            new Error(422, "Invalid email format", source: ["email"]),
        ];

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with($entity)
            ->willReturn($validationErrors);

        // Must not reach saving
        //
        // Не должно дойти до сохранения
        $saver->expects($this->never())->method("save");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(422, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testCreateActionNotFound(): void
    {
        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "nonexistent",
            actionType: ActionType::Create,
            rawContent: "{}",
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testCreateModelNotFound(): void
    {
        // Arrange - add an action for a non-existent model
        //
        // Arrange - добавляем action для несуществующей модели
        $configWithBadAction = new Config(
            models: $this->config->models,
            actions: [
                new Action(
                    type: ActionType::Create,
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
            locators: $this->createStub(ListLocators::class),
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
            actionType: ActionType::Create,
            rawContent: "{}",
        );
    }

    public function testCreateWithInvalidJson(): void
    {
        // Arrange
        //
        // Подготовка
        $invalidJson = "{invalid json}";

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $invalidJson,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testCreateWithEmptyContent(): void
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
        $entity = new Author(
            id: "1",
            name: "Default",
            email: "default@example.com",
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Create, "", null)
            ->willReturn($entity);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->willReturn($entity);

        // Act - pass null, which is converted to '{}'
        //
        // Act - передаем null, что конвертируется в '{}'
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(201, $result->statusCode);
    }

    public function testCreateWithNestedRelations(): void
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
        $rawContent = json_encode(
            [
                "name" => "John Doe",
                "email" => "john@example.com",
                "address" => [
                    "city" => "New York",
                    "street" => "5th Avenue",
                ],
                "books" => [["title" => "Book 1"], ["title" => "Book 2"]],
            ],
            JSON_THROW_ON_ERROR,
        );

        $address = new Address(id: "1", city: "New York", street: "5th Avenue");
        $book1 = new Book(id: "1", title: "Book 1");
        $book2 = new Book(id: "2", title: "Book 2");
        $entity = new Author(
            id: "1",
            name: "John Doe",
            email: "john@example.com",
            address: $address,
            books: [$book1, $book2],
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $this->anything(), ActionType::Create, "", null)
            ->willReturn($entity);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(201, $result->statusCode);
        $this->assertTrue($result->encoded);
    }

    public function testCreateWithIncludeParameter(): void
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
        $rawContent = json_encode(
            ["name" => "John Doe"],
            JSON_THROW_ON_ERROR,
        );
        $entity = new Author(id: "1", name: "John Doe");

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $this->anything(),
                ActionType::Create,
                "address,books",
                null,
            )
            ->willReturn($entity);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            queryParams: ["included" => "address,books"],
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(201, $result->statusCode);
    }

    public function testCreateReturnsCorrectHeaders(): void
    {
        // Arrange
        //
        // Подготовка
        $rawContent = json_encode(
            ["name" => "John Doe"],
            JSON_THROW_ON_ERROR,
        );
        $entity = new Author(id: "1", name: "John Doe");

        $this->mappingToObject->method("map")->willReturn($entity);

        $this->constraintChecker->method("check")->willReturn([]);

        $this->saver->method("save")->willReturn($entity);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        // By default there are no additional headers
        //
        // По умолчанию нет дополнительных заголовков
        $this->assertEmpty($result->headers);
        $this->assertEquals(201, $result->statusCode);
    }

    public function testCreateWithMultipleValidationErrors(): void
    {
        // Arrange
        //
        // Подготовка
        $rawContent = json_encode(
            [
                "name" => "",
                "email" => "",
            ],
            JSON_THROW_ON_ERROR,
        );

        $entity = new Author(id: null, name: "", email: "");

        $this->mappingToObject->method("map")->willReturn($entity);

        $validationErrors = [
            new Error(422, "Name is required", source: ["name"]),
            new Error(422, "Email is required", source: ["email"]),
            new Error(
                422,
                "Name must be at least 3 characters",
                source: ["name"],
            ),
        ];

        $this->constraintChecker->method("check")->willReturn($validationErrors);

        // Act
        //
        // Действие
        /** @var ApiResult<Errors> $result */
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(422, $result->statusCode);
        $this->assertObjectHasProperty("errors", $result->data);
        $this->assertCount(3, $result->data->errors);
    }

    public function testCreateWithDifferentValidationErrorCodes(): void
    {
        // Arrange
        //
        // Подготовка
        $rawContent = json_encode(
            ["name" => "John"],
            JSON_THROW_ON_ERROR,
        );
        $entity = new Author(id: null, name: "John");

        $this->mappingToObject->method("map")->willReturn($entity);

        // Different error codes - 400 must be returned
        //
        // Разные коды ошибок - должен вернуться 400
        $validationErrors = [
            new Error(422, "Validation error"),
            new Error(409, "Conflict error"),
        ];

        $this->constraintChecker->method("check")->willReturn($validationErrors);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        // Default code for different statuses
        //
        // Default code для разных статусов
        $this->assertEquals(400, $result->statusCode);
    }

    /**
     * The EntitySaverInterface::save() contract: the same instance is returned.
     * Another object — SaverContractViolationException (500), not 201 with it.
     *
     * Контракт EntitySaverInterface::save(): возвращается тот же экземпляр.
     * Другой объект — SaverContractViolationException (500), а не 201 с ним.
     */
    public function testSaverReturningDifferentInstanceIsContractViolation(): void
    {
        $entity = new Author(id: "1", name: "John Doe");

        $this->mappingToObject->method("map")->willReturn($entity);
        $this->constraintChecker->method("check")->willReturn([]);
        $this->saver->method("save")->willReturn(new Author(id: "1", name: "John Doe"));

        $this->expectException(SaverContractViolationException::class);
        $this->expectExceptionMessageMatches("/Saver 'default' of action 'create' on model 'author'/");

        $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: '{"name": "John Doe"}',
        );
    }
}
