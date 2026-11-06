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
use UniMethod\Frame\Integration\{ApiService, ConstraintCheckerInterface};
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
    ReadAddress,
    ReadAuthor,
    ReadBook,
    ReadCountry,
    ReadPublisher,
};
use UniMethod\Frame\Validator\ActionValidator;

class ApiServiceReadTest extends TestCase
{
    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    private EntityLocatorInterface&Stub $locator;

    private Config $config;

    private MappingFromObject&Stub $mappingFromObject;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\ReadBook",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;

                class ReadBook {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $title = null,
                    ) {}
                }

                class ReadPublisher {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                    ) {}
                }

                class ReadCountry {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                    ) {}
                }

                class ReadAddress {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $city = null,
                        public ?string $street = null,
                        public ?ReadCountry $country = null,
                    ) {}
                }

                class ReadAuthor {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        public ?string $email = null,
                        public ?string $bio = null,
                        public ?ReadAddress $address = null,
                        public ?ReadPublisher $publisher = null,
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

    private function stubMappingFromObject(MappingFromObject&Stub $mappingFromObject): void
    {
        $mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $instance,
                ?array $include,
            ): \stdClass {
                $result = (object) [
                    "id" => $instance->id,
                    "name" => $instance->name ?? null,
                ];

                // If there is an include, add the relations
                //
                // Если есть include, добавляем связи
                if (
                    $include !== null &&
                    property_exists($instance, "address") &&
                    in_array("address", $include, true)
                ) {
                    $result->address = $instance->address
                        ? (object) ["id" => $instance->address->id]
                        : null;
                }

                return $result;
            });
    }

    protected function setUp(): void
    {
        // Create stub objects
        //
        // Создаем stub-объекты
        $this->locator = $this->createStub(EntityLocatorInterface::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);

        // Create the configuration
        //
        // Создаем конфигурацию
        $this->config = new Config(
            models: [
                new Model(
                    class: ReadAuthor::class,
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
                    class: ReadAddress::class,
                    type: "address",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "city", type: "string"),
                        new Attribute(name: "street", type: "string"),
                    ],
                    relations: [
                        new Relation(
                            name: "country",
                            type: "manyToOne",
                            targetModel: "country",
                        ),
                    ],
                ),
                new Model(
                    class: ReadBook::class,
                    type: "book",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "title", type: "string"),
                    ],
                ),
                new Model(
                    class: ReadPublisher::class,
                    type: "publisher",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
                new Model(
                    class: ReadCountry::class,
                    type: "country",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "author",
                    include: ["address", "books"],
                    entityLocator: "default",
                ),
                new Action(
                    type: ActionType::Read,
                    model: "address",
                    include: ["country"],
                    entityLocator: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
        );

        // Set up the mapping
        //
        // Настраиваем маппинг
        $this->stubMappingFromObject($this->mappingFromObject);

        // Initialize the service
        //
        // Инициализируем сервис
        $this->serviceFactory = fn (): ApiService => new ApiService(
            constraintChecker: $this->createStub(ConstraintCheckerInterface::class),
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );
    }

    public function testSuccessfulRead(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(
            id: $id,
            name: "John Doe",
            email: "john@example.com",
        );

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(ReadAuthor::class, $id, ["address", "books"])
            ->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertTrue($result->encoded);
        $this->assertIsString($result->data);
        $this->assertEmpty($result->headers);
    }

    public function testReadWithoutId(): void
    {
        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testReadNotFound(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "999";

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(ReadAuthor::class, $id, ["address", "books"])
            // Entity not found
            //
            // Сущность не найдена
            ->willReturn(null);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testReadActionNotFound(): void
    {
        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "nonexistent",
            actionType: ActionType::Read,
            id: "123",
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testReadModelNotFound(): void
    {
        // Arrange
        //
        // Подготовка
        $configWithBadAction = new Config(
            models: $this->config->models,
            actions: [
                new Action(type: ActionType::Read, model: "nonexistent_model"),
            ],
            locators: ["default" => EntityLocatorInterface::class],
        );

        $service = new ApiService(
            constraintChecker: $this->createStub(ConstraintCheckerInterface::class),
            config: $configWithBadAction,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(
            "Model not found: nonexistent_model",
        );

        $service->execute(
            modelType: "nonexistent_model",
            actionType: ActionType::Read,
            id: "123",
        );
    }

    public function testReadWithDefaultInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $address = new ReadAddress(id: "456", city: "New York");
        $author = new ReadAuthor(id: $id, name: "John Doe", address: $address);

        $locator
            ->method("find")
            ->with(ReadAuthor::class, $id)
            ->willReturn($author);

        $mappingFromObject
            ->expects($this->once())
            ->method("map")
            ->with(
                "author",
                $author,
                $this->callback(function ($include): bool {
                    // Check that the default relations from action->include are included
                    //
                    // Проверяем что включены дефолтные связи из action->include
                    return is_array($include) &&
                        in_array("address", $include, true) &&
                        in_array("books", $include, true);
                }),
            )
            ->willReturn(
                (object) [
                    "id" => $id,
                    "name" => "John Doe",
                    "address" => (object) ["id" => "456"],
                ],
            );

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertTrue($result->encoded);
    }

    public function testReadWithCustomInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $mappingFromObject->expects($this->once())->method("map")->with(
            "author",
            $author,
            $this->callback(function ($include): bool {
                // Check that include is merged with the default one
                //
                // Проверяем что include объединён с дефолтным
                return is_array($include) &&
                    in_array("address", $include, true) &&
                    in_array("books", $include, true) &&
                    in_array("publisher", $include, true);
            }),
        );

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            // Add to the default
            //
            // Добавляем к дефолтному
            queryParams: ["included" => "publisher"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadWithMultipleIncludes(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $mappingFromObject->expects($this->once())->method("map")->with(
            "author",
            $author,
            $this->callback(function ($include): bool {
                return is_array($include) &&
                    in_array("address", $include, true) &&
                    in_array("books", $include, true) &&
                    in_array("publisher", $include, true);
            }),
        );

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            queryParams: ["included" => "address,books,publisher"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadUsesCorrectLocator(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";

        $locator
            ->expects($this->once())
            ->method("find")
            // Check the correct class
            //
            // Проверяем правильный класс
            ->with(ReadAuthor::class, $id)
            ->willReturn(new ReadAuthor(id: $id, name: "Test"));

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadDoesNotModifyEntity(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(
            id: $id,
            name: "Original Name",
            email: "original@example.com",
        );

        $this->locator->method("find")->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        // Check that the data has not changed
        //
        // Проверяем что данные не изменились
        $this->assertEquals("Original Name", $author->name);
        $this->assertEquals("original@example.com", $author->email);
    }

    public function testReadWithNumericId(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "12345";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(ReadAuthor::class, $id, ["address", "books"])
            ->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadWithStringId(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "uuid-123-abc";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(ReadAuthor::class, $id, ["address", "books"])
            ->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadReturnsJsonString(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertTrue($result->encoded);
        $this->assertIsString($result->data);

        // Check that this is valid JSON
        //
        // Проверяем что это валидный JSON
        $decoded = json_decode($result->data);
        $this->assertIsObject($decoded);
    }

    public function testReadWithEmptyInclude(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        // Act - pass an empty include
        //
        // Act - передаем пустой include
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            queryParams: ["included" => ""],
            id: $id,
        );

        // Assert - the default includes from the action must be used
        //
        // Assert - должны использоваться дефолтные include из action
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadEntityWithAllRelationTypes(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $address = new ReadAddress(id: "456", city: "NYC");
        $book1 = new ReadBook(id: "1", title: "ReadBook 1");
        $book2 = new ReadBook(id: "2", title: "ReadBook 2");
        $publisher = new ReadPublisher(id: "789", name: "Big Pub");

        $author = new ReadAuthor(
            id: $id,
            name: "John Doe",
            address: $address, // oneToOne
            publisher: $publisher, // manyToOne
            books: [$book1, $book2], // oneToMany
        );

        $this->locator->method("find")->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            queryParams: ["included" => "address,books,publisher"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadWithNullRelation(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe", address: null);

        $this->locator->method("find")->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            queryParams: ["included" => "address"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadDoesNotCallSaver(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $saverMock = $this->createMock(EntitySaverInterface::class);
        // Read must not call save
        //
        // Read не должен вызывать save
        $saverMock->expects($this->never())->method("save");

        $service = new ApiService(
            constraintChecker: $this->createStub(ConstraintCheckerInterface::class),
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: new ListSavers(["default" => $saverMock]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        // Act
        //
        // Действие
        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadPassesIncludeToLocator(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        // Check that include is passed to the locator
        //
        // Проверяем что include передаётся в locator
        $locator
            ->expects($this->once())
            ->method("find")
            ->with(
                ReadAuthor::class,
                $id,
                $this->callback(function (array $include): bool {
                    // There must be the default includes from the action: ['address', 'books']
                    //
                    // Должны быть дефолтные include из action: ['address', 'books']
                    return in_array("address", $include, true) &&
                        in_array("books", $include, true);
                }),
            )
            ->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadMergesQueryIncludeWithActionInclude(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        // Check that the query include is merged with the action include
        //
        // Проверяем что query include объединяется с action include
        $locator
            ->expects($this->once())
            ->method("find")
            ->with(
                ReadAuthor::class,
                $id,
                $this->callback(function (array $include): bool {
                    // action include: ['address', 'books'] + query include: ['publisher']
                    //
                    // include действия: ['address', 'books'] + include запроса: ['publisher']
                    return in_array("address", $include, true) &&
                        in_array("books", $include, true) &&
                        in_array("publisher", $include, true);
                }),
            )
            ->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            queryParams: ["included" => "publisher"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadWithNestedInclude(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "456";
        $country = new ReadCountry(id: "789", name: "USA");
        $address = new ReadAddress(id: $id, city: "NYC", country: $country);

        $locator = $this->createMock(EntityLocatorInterface::class);
        $locator
            ->expects($this->once())
            ->method("find")
            ->with(
                ReadAddress::class,
                $id,
                $this->callback(function (array $include): bool {
                    // action include: ['country']
                    //
                    // include действия: ['country']
                    return in_array("country", $include, true);
                }),
            )
            ->willReturn($address);

        $service = new ApiService(
            constraintChecker: $this->createStub(ConstraintCheckerInterface::class),
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $locator]),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        // Act
        //
        // Действие
        $result = $service->execute(
            modelType: "address",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadWithDeepNestedInclude(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        // Check passing a nested include (address.country)
        //
        // Проверяем передачу вложенного include (address.country)
        $locator
            ->expects($this->once())
            ->method("find")
            ->with(
                ReadAuthor::class,
                $id,
                $this->callback(function (array $include): bool {
                    return in_array("address", $include, true) &&
                        in_array("books", $include, true) &&
                        in_array("address.country", $include, true);
                }),
            )
            ->willReturn($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Read,
            queryParams: ["included" => "address.country"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testReadWithEmptyIncludeArray(): void
    {
        // Arrange - create an action without default includes
        //
        // Arrange - создаём action без дефолтных include
        $configWithNoInclude = new Config(
            models: $this->config->models,
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "author",
                    include: [],
                    // empty include
                    //
                    // пустой include
                    entityLocator: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
        );

        $id = "123";
        $author = new ReadAuthor(id: $id, name: "John Doe");

        $locator = $this->createMock(EntityLocatorInterface::class);
        $locator
            ->expects($this->once())
            ->method("find")
            // empty include array
            //
            // пустой массив include
            ->with(ReadAuthor::class, $id, [])
            ->willReturn($author);

        $service = new ApiService(
            constraintChecker: $this->createStub(ConstraintCheckerInterface::class),
            config: $configWithNoInclude,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->createStub(MappingToObject::class),
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $locator]),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        // Act
        //
        // Действие
        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::Read,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }
}
