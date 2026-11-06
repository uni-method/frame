<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
};
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\{
    ApiService,
    ConstraintCheckerInterface,
    ConstraintContext,
    ConstraintPhase,
    Error,
    UserInterface,
    UserProviderInterface,
};
use UniMethod\Frame\Integration\Exception\ActionAliasNotConfiguredException;
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
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
use UniMethod\Frame\Tests\Unit\Integration\Stub\ConstraintPost;
use UniMethod\Frame\Validator\ActionValidator;

/**
 * Call points of ConstraintCheckerInterface and the contents of ConstraintContext
 * by phases AfterLocate / AfterInput / ListQuery.
 *
 * Точки вызова ConstraintCheckerInterface и содержимое ConstraintContext
 * по фазам AfterLocate / AfterInput / ListQuery.
 */
final class ApiServiceConstraintsTest extends TestCase
{
    private ConstraintCheckerInterface&Stub $constraintChecker;

    private EntityLocatorInterface&Stub $locator;

    private EntitySaverInterface&Stub $saver;

    private EntityRemoverInterface&Stub $remover;

    private MappingToObject&Stub $mappingToObject;

    private MappingFromObject&Stub $mappingFromObject;

    private MappingToListAction&Stub $mappingToListAction;

    private UserProviderInterface&Stub $userProvider;

    private UserInterface&Stub $user;

    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\ConstraintPost",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;
                class ConstraintPost {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $title = null,
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
            ->willReturnCallback(
                fn (string $type, object $instance): object => (object) [
                    "id" => $instance->id ?? null,
                    "title" => $instance->title ?? null,
                ],
            );
    }

    protected function setUp(): void
    {
        $this->constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $this->locator = $this->createStub(EntityLocatorInterface::class);
        $this->saver = $this->createStub(EntitySaverInterface::class);
        $this->remover = $this->createStub(EntityRemoverInterface::class);
        $this->mappingToObject = $this->createStub(MappingToObject::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);
        $this->mappingToListAction = $this->createStub(MappingToListAction::class);
        $this->userProvider = $this->createStub(UserProviderInterface::class);
        $this->user = $this->createStub(UserInterface::class);

        $this->userProvider->method("getCurrentUser")->willReturn($this->user);
        $this->userProvider->method("canAccessAction")->willReturn(true);

        $this->stubMappingFromObject($this->mappingFromObject);

        $config = new Config(
            models: [
                new Model(
                    class: ConstraintPost::class,
                    type: "post",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "title", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Create, model: "post", entitySaver: "default"),
                new Action(type: ActionType::Read, model: "post", entityLocator: "default"),
                new Action(
                    type: ActionType::Update,
                    model: "post",
                    entityLocator: "default",
                    entitySaver: "default",
                ),
                new Action(
                    type: ActionType::Delete,
                    model: "post",
                    entityLocator: "default",
                    entityRemover: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
            savers: ["default" => EntitySaverInterface::class],
            removers: ["default" => EntityRemoverInterface::class],
        );

        $this->serviceFactory = fn (): ApiService => new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: new ListSavers(["default" => $this->saver]),
            removers: new ListRemovers(["default" => $this->remover]),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $this->userProvider,
        );
    }

    // -------------------------------------------
    // AfterLocate: read
    // -------------------------------------------

    public function testReadChecksLocatedEntityWithAfterLocateContext(): void
    {
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $post = new ConstraintPost(id: "42", title: "Hello");
        $this->locator->method("find")->willReturn($post);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with(
                $this->identicalTo($post),
                $this->callback(
                    fn (ConstraintContext $context): bool => $context->modelType === "post"
                        && $context->actionType === ActionType::Read
                        && $context->phase === ConstraintPhase::AfterLocate
                        && $context->id === "42"
                        && $context->user === $this->user
                        && $context->original === null,
                ),
            )
            ->willReturn([]);

        $result = $this->service()->execute("post", ActionType::Read, id: "42");

        $this->assertSame(200, $result->statusCode);
    }

    public function testReadReturnsCheckerErrorsAndSkipsOutput(): void
    {
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $this->stubMappingFromObject($mappingFromObject);

        $this->locator->method("find")->willReturn(new ConstraintPost(id: "42"));

        $this->constraintChecker
            ->method("check")
            ->willReturn([new Error(403, "Owner or admin only")]);

        $mappingFromObject->expects($this->never())->method("map");

        $result = $this->service()->execute("post", ActionType::Read, id: "42");

        $this->assertSame(403, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    public function testReadNotFoundDoesNotCallChecker(): void
    {
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $this->locator->method("find")->willReturn(null);

        $constraintChecker->expects($this->never())->method("check");

        $result = $this->service()->execute("post", ActionType::Read, id: "42");

        $this->assertSame(404, $result->statusCode);
    }

    public function testDomainExceptionFromCheckerBecomesErrorResponse(): void
    {
        $this->locator->method("find")->willReturn(new ConstraintPost(id: "42"));

        $this->constraintChecker
            ->method("check")
            ->willThrowException(
                new class ("Access denied") extends RuntimeException implements FrameDomainException {
                    public function getHttpErrorCode(): int
                    {
                        return 403;
                    }
                },
            );

        $result = $this->service()->execute("post", ActionType::Read, id: "42");

        $this->assertSame(403, $result->statusCode);
    }

    // -------------------------------------------
    // AfterLocate: delete
    // -------------------------------------------

    public function testDeleteChecksBeforeRemoverAndStopsOnErrors(): void
    {
        $remover = $this->createMock(EntityRemoverInterface::class);
        $this->remover = $remover;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $post = new ConstraintPost(id: "42");
        $this->locator->method("find")->willReturn($post);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with(
                $this->identicalTo($post),
                $this->callback(
                    fn (ConstraintContext $context): bool => $context->actionType === ActionType::Delete
                        && $context->phase === ConstraintPhase::AfterLocate
                        && $context->id === "42",
                ),
            )
            ->willReturn([new Error(403, "Owner or admin only")]);

        $remover->expects($this->never())->method("remove");

        $result = $this->service()->execute("post", ActionType::Delete, id: "42");

        $this->assertSame(403, $result->statusCode);
    }

    // -------------------------------------------
    // Update: AfterLocate → AfterInput
    // -------------------------------------------

    public function testUpdateRunsAfterLocateThenAfterInputWithOriginalSnapshot(): void
    {
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $located = new ConstraintPost(id: "42", title: "Old");
        $this->locator->method("find")->willReturn($located);

        // The mapper mutates the same instance, as an ORM with an identity map does
        //
        // Маппер мутирует тот же инстанс, как это делает ORM с identity map
        $this->mappingToObject
            ->method("map")
            ->willReturnCallback(function () use ($located): object {
                $located->title = "New";
                return $located;
            });
        $this->saver->method("save")->willReturnArgument(0);

        $phases = [];
        $constraintChecker
            ->expects($this->exactly(2))
            ->method("check")
            ->willReturnCallback(function (
                object $object,
                ConstraintContext $context
            ) use (
                $located,
                &$phases,
            ): array {
                $phases[] = $context->phase;
                TestCase::assertSame($located, $object);
                TestCase::assertSame(ActionType::Update, $context->actionType);
                TestCase::assertSame("42", $context->id);
                TestCase::assertSame($this->user, $context->user);

                if ($context->phase === ConstraintPhase::AfterLocate) {
                    TestCase::assertNull($context->original);
                    TestCase::assertSame("Old", $object->title);
                } else {
                    // original is a clone of the entity with the "before" value, not the same object
                    //
                    // original — clone сущности со значением «до», не тот же объект
                    TestCase::assertInstanceOf($located::class, $context->original);
                    TestCase::assertNotSame($located, $context->original);
                    TestCase::assertSame("Old", $context->original->title);
                    TestCase::assertSame("New", $object->title);
                }

                return [];
            });

        $result = $this->service()->execute(
            "post",
            ActionType::Update,
            rawContent: '{"title":"New"}',
            id: "42",
        );

        $this->assertSame(200, $result->statusCode);
        $this->assertSame([ConstraintPhase::AfterLocate, ConstraintPhase::AfterInput], $phases);
    }

    public function testUpdateAfterLocateFailureSkipsMappingAndSave(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $this->locator->method("find")->willReturn(new ConstraintPost(id: "42"));

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->willReturn([new Error(403, "Owner or admin only")]);

        $mappingToObject->expects($this->never())->method("map");
        $saver->expects($this->never())->method("save");

        $result = $this->service()->execute(
            "post",
            ActionType::Update,
            rawContent: '{"title":"New"}',
            id: "42",
        );

        $this->assertSame(403, $result->statusCode);
    }

    public function testUpdateNotFoundReturns404BeforeAnyCheck(): void
    {
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $this->locator->method("find")->willReturn(null);

        $constraintChecker->expects($this->never())->method("check");
        $mappingToObject->expects($this->never())->method("map");

        $result = $this->service()->execute(
            "post",
            ActionType::Update,
            rawContent: '{"title":"New"}',
            id: "42",
        );

        $this->assertSame(404, $result->statusCode);
    }

    // -------------------------------------------
    // Create: AfterInput only
    // -------------------------------------------
    //
    // -------------------------------------------
    // Create: только AfterInput
    // -------------------------------------------

    public function testCreateChecksOnlyAfterInputWithoutIdAndOriginal(): void
    {
        $locator = $this->createMock(EntityLocatorInterface::class);
        $this->locator = $locator;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $post = new ConstraintPost(id: "1", title: "New");
        $this->mappingToObject->method("map")->willReturn($post);
        $this->saver->method("save")->willReturn($post);

        $locator->expects($this->never())->method("find");

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with(
                $this->identicalTo($post),
                $this->callback(
                    fn (ConstraintContext $context): bool => $context->modelType === "post"
                        && $context->actionType === ActionType::Create
                        && $context->phase === ConstraintPhase::AfterInput
                        && $context->id === null
                        && $context->original === null
                        && $context->user === $this->user,
                ),
            )
            ->willReturn([]);

        $result = $this->service()->execute(
            "post",
            ActionType::Create,
            rawContent: '{"title":"New"}',
        );

        $this->assertSame(201, $result->statusCode);
    }

    // -------------------------------------------
    // Without a user provider
    // -------------------------------------------
    //
    // -------------------------------------------
    // Без провайдера пользователя
    // -------------------------------------------

    public function testContextUserIsNullWithoutUserProvider(): void
    {
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        $post = new ConstraintPost(id: "42");
        $this->locator->method("find")->willReturn($post);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->with(
                $this->anything(),
                $this->callback(fn (ConstraintContext $context): bool => !$context->user instanceof UserInterface),
            )
            ->willReturn([]);

        $service = new ApiService(
            constraintChecker: $constraintChecker,
            config: new Config(
                models: [
                    new Model(
                        class: ConstraintPost::class,
                        type: "post",
                        attributes: [new Attribute(name: "id", type: "string")],
                    ),
                ],
                actions: [new Action(type: ActionType::Read, model: "post", entityLocator: "default")],
                locators: ["default" => EntityLocatorInterface::class],
            ),
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["default" => $this->locator]),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $result = $service->execute("post", ActionType::Read, id: "42");

        $this->assertSame(200, $result->statusCode);
    }

    // -------------------------------------------
    // ConstraintContext: factories
    // -------------------------------------------
    //
    // -------------------------------------------
    // ConstraintContext: фабрики
    // -------------------------------------------

    public function testListQueryFactoryFixesActionAndPhase(): void
    {
        $context = ConstraintContext::listQuery("post", $this->user);

        $this->assertSame("post", $context->modelType);
        $this->assertSame(ActionType::List, $context->actionType);
        $this->assertSame(ConstraintPhase::ListQuery, $context->phase);
        $this->assertSame($this->user, $context->user);
        $this->assertNull($context->id);
        $this->assertNull($context->original);
    }

    public function testAfterLocateFactoryRequiresId(): void
    {
        $context = ConstraintContext::afterLocate("post", ActionType::Read, 7);

        $this->assertSame(ConstraintPhase::AfterLocate, $context->phase);
        $this->assertSame(7, $context->id);
        $this->assertNull($context->user);
        $this->assertNull($context->original);
    }

    public function testAfterInputFactoryCarriesOriginal(): void
    {
        $original = new ConstraintPost(id: "42", title: "Old");
        $context = ConstraintContext::afterInput("post", ActionType::Update, null, "42", $original);

        $this->assertSame(ConstraintPhase::AfterInput, $context->phase);
        $this->assertSame("42", $context->id);
        $this->assertSame($original, $context->original);
    }

    public function testReadWithoutLocatorAliasThrowsNotConfigured(): void
    {
        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: new Config(
                models: [
                    new Model(
                        class: ConstraintPost::class,
                        type: "post",
                        attributes: [new Attribute(name: "id", type: "string")],
                    ),
                ],
                actions: [new Action(type: ActionType::Read, model: "post")],
            ),
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->mappingToListAction,
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators([]),
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
        );

        $this->expectException(ActionAliasNotConfiguredException::class);
        $this->expectExceptionMessage("Action 'read' on model 'post' has no 'entityLocator' configured.");

        $service->execute("post", ActionType::Read, id: "42");
    }
}
