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
use UniMethod\Frame\Integration\{
    ApiService,
    ConstraintCheckerInterface,
    ConstraintContext,
    ConstraintPhase,
};
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
    EntityRemoverInterface,
    EntitySaverInterface,
    ListLocators,
    ListRemovers,
    ListSavers,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Integration\Stub\{
    DeleteAddress,
    DeleteAuthor,
    DeleteBook,
    DeleteCountry,
    DeletePublisher,
};
use UniMethod\Frame\Validator\ActionValidator;

class ApiServiceDeleteTest extends TestCase
{
    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    private EntityLocatorInterface&Stub $locator;

    private EntityRemoverInterface&Stub $remover;

    private Config $config;

    private MappingFromObject&Stub $mappingFromObject;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\DeleteBook",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;

                class DeleteBook {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $title = null,
                    ) {}
                }

                class DeletePublisher {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                    ) {}
                }

                class DeleteCountry {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                    ) {}
                }

                class DeleteAddress {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $city = null,
                        public ?DeleteCountry $country = null,
                    ) {}
                }

                class DeleteAuthor {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        public ?string $email = null,
                        public ?DeleteAddress $address = null,
                        public ?DeletePublisher $publisher = null,
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
            ->willReturnCallback(function (string $type, object $instance): \stdClass {
                return (object) [
                    "id" => $instance->id,
                    "name" => $instance->name ?? null,
                ];
            });
    }

    protected function setUp(): void
    {
        // Create stub objects
        //
        // Создаем stub-объекты
        $this->locator = $this->createStub(EntityLocatorInterface::class);
        $this->remover = $this->createStub(EntityRemoverInterface::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);

        // Create the configuration
        //
        // Создаем конфигурацию
        $this->config = new Config(
            models: [
                new Model(
                    class: DeleteAuthor::class,
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
                        new Relation(
                            name: "publisher",
                            type: "manyToOne",
                            targetModel: "publisher",
                        ),
                    ],
                ),
                new Model(
                    class: DeleteAddress::class,
                    type: "address",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "city", type: "string"),
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
                    class: DeleteBook::class,
                    type: "book",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "title", type: "string"),
                    ],
                ),
                new Model(
                    class: DeletePublisher::class,
                    type: "publisher",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
                new Model(
                    class: DeleteCountry::class,
                    type: "country",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Delete,
                    model: "author",
                    include: ["address", "books"],
                    entityLocator: "default",
                    entityRemover: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
            removers: ["default" => EntityRemoverInterface::class],
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
            removers: new ListRemovers(["default" => $this->remover]),
            manipulators: $this->createStub(MutatorRegistry::class),
        );
    }

    public function testSuccessfulDelete(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(
            id: $id,
            name: "John Doe",
            email: "john@example.com",
        );

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(DeleteAuthor::class, $id)
            ->willReturn($author);

        $remover->expects($this->once())->method("remove")->with($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
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

    public function testDeleteWithoutId(): void
    {
        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertIsObject($result->data);
        $this->assertObjectHasProperty("errors", $result->data);
    }

    public function testDeleteNotFound(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "999";

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(DeleteAuthor::class, $id)
            // Entity not found
            //
            // Сущность не найдена
            ->willReturn(null);

        // Must not be called
        //
        // Не должен вызываться
        $remover->expects($this->never())->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testDeleteActionNotFound(): void
    {
        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "nonexistent",
            actionType: ActionType::Delete,
            id: "123",
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testDeleteModelNotFound(): void
    {
        // Arrange
        //
        // Подготовка
        $configWithBadAction = new Config(
            models: $this->config->models,
            actions: [
                new Action(
                    type: ActionType::Delete,
                    model: "nonexistent_model",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
            removers: ["default" => EntityRemoverInterface::class],
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
            removers: new ListRemovers(["default" => $this->remover]),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage(
            "Model not found: nonexistent_model",
        );

        $service->execute(
            modelType: "nonexistent_model",
            actionType: ActionType::Delete,
            id: "123",
        );
    }

    public function testDeleteReturnsDeletedEntity(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        // Arrange - Delete returns the deleted entity in the response
        //
        // Arrange - Delete возвращает удалённую сущность в ответе
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        $mappingFromObject
            ->expects($this->once())
            ->method("map")
            ->with("author", $author, $this->anything())
            ->willReturn((object) ["id" => $id, "name" => "John Doe"]);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertTrue($result->encoded);
        $this->assertIsString($result->data);
    }

    public function testDeleteWithDefaultInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        $mappingFromObject->expects($this->once())->method("map")->with(
            "author",
            $author,
            $this->callback(function ($include): bool {
                // Check the default includes
                //
                // Проверяем дефолтные include
                return is_array($include) &&
                    in_array("address", $include, true) &&
                    in_array("books", $include, true);
            }),
        );

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteWithCustomInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        $mappingFromObject->expects($this->once())->method("map")->with(
            "author",
            $author,
            $this->callback(function ($include): bool {
                // Check merging with the defaults
                //
                // Проверяем объединение с дефолтными
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
            actionType: ActionType::Delete,
            queryParams: ["included" => "publisher"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteWithNestedInclude(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        $mappingFromObject->expects($this->once())->method("map")->with(
            "author",
            $author,
            $this->callback(function ($include): bool {
                return is_array($include) &&
                    in_array("address.country", $include, true);
            }),
        );

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            queryParams: ["included" => "address.country"],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteUsesCorrectRemover(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;

        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "Test");

        $this->locator->method("find")->willReturn($author);

        $remover
            ->expects($this->once())
            ->method("remove")
            // Check that the same entity is passed
            //
            // Проверяем что передаётся та же сущность
            ->with($this->identicalTo($author));

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteDoesNotCallSaver(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        $saverMock = $this->createMock(EntitySaverInterface::class);
        // Delete must not call save
        //
        // Delete не должен вызывать save
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
            removers: new ListRemovers(["default" => $this->remover]),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        // Act
        //
        // Действие
        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteChecksConstraintsAfterLocate(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        $constraintCheckerMock = $this->createMock(ConstraintCheckerInterface::class);
        $constraintCheckerMock
            ->expects($this->once())
            ->method("check")
            ->with(
                $author,
                $this->callback(
                    fn (ConstraintContext $context): bool => $context->phase === ConstraintPhase::AfterLocate
                        && $context->actionType === ActionType::Delete
                        && $context->id === $id,
                ),
            )
            ->willReturn([]);

        $service = new ApiService(
            constraintChecker: $constraintCheckerMock,
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
            removers: new ListRemovers(["default" => $this->remover]),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        // Act
        //
        // Действие
        $result = $service->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteReturnsJsonString(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
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

    public function testDeleteWithNumericId(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "12345";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(DeleteAuthor::class, $id)
            ->willReturn($author);

        $this->remover->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteWithStringId(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange
        //
        // Подготовка
        $id = "uuid-123-abc";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $locator
            ->expects($this->once())
            ->method("find")
            ->with(DeleteAuthor::class, $id)
            ->willReturn($author);

        $this->remover->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteReturns200NotNoContent(): void
    {
        // Arrange - Delete returns 200 with a body, not 204 No Content
        //
        // Arrange - Delete возвращает 200 с телом, а не 204 No Content
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        $this->assertNotEquals(204, $result->statusCode);
        // There is a response body
        //
        // Есть тело ответа
        $this->assertNotEmpty($result->data);
    }

    public function testDeleteEntityWithRelations(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;

        // Arrange - deleting an entity with relations
        //
        // Arrange - удаление сущности со связями
        $id = "123";
        $address = new DeleteAddress(id: "456", city: "NYC");
        $book1 = new DeleteBook(id: "1", title: "Book 1");
        $publisher = new DeletePublisher(id: "789", name: "Publisher");

        $author = new DeleteAuthor(
            id: $id,
            name: "John Doe",
            address: $address,
            publisher: $publisher,
            books: [$book1],
        );

        $this->locator->method("find")->willReturn($author);

        $remover->expects($this->once())->method("remove")->with($author);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteWithEmptyInclude(): void
    {
        // Arrange
        //
        // Подготовка
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $this->locator->method("find")->willReturn($author);

        $this->remover->method("remove");

        // Act - an empty include uses the default values
        //
        // Act - пустой include использует дефолтные значения
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            queryParams: ["included" => ""],
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }

    public function testDeleteCallsLocatorBeforeRemover(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange - check the order of calls
        //
        // Arrange - проверяем порядок вызовов
        $id = "123";
        $author = new DeleteAuthor(id: $id, name: "John Doe");

        $callOrder = [];

        $locator
            ->expects($this->once())
            ->method("find")
            ->willReturnCallback(function () use (
                $author,
                &$callOrder,
            ): DeleteAuthor {
                $callOrder[] = "find";
                return $author;
            });

        $remover
            ->expects($this->once())
            ->method("remove")
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = "remove";
            });

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
        // Correct order
        //
        // Правильный порядок
        $this->assertEquals(["find", "remove"], $callOrder);
    }

    public function testDeleteOnlyRemovesIfEntityExists(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange - if the entity is not found, the remover is not called
        //
        // Arrange - если сущность не найдена, remover не вызывается
        $id = "999";

        $locator
            ->expects($this->once())
            ->method("find")
            ->willReturn(null);

        // Must NOT be called
        //
        // НЕ должен вызваться
        $remover->expects($this->never())->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result->statusCode);
    }

    public function testDeleteUsesCorrectLocatorFromConfig(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;

        // Arrange - check that the correct locator from the configuration is used
        //
        // Arrange - проверяем что используется правильный locator из конфигурации
        $id = "123";

        $locator
            ->expects($this->once())
            ->method("find")
            // The correct class from the configuration
            //
            // Правильный класс из конфигурации
            ->with(DeleteAuthor::class, $id)
            ->willReturn(new DeleteAuthor(id: $id, name: "Test"));

        $this->remover->method("remove");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Delete,
            id: $id,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(200, $result->statusCode);
    }
}
