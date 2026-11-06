<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    AccessConfig,
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    RelationWriteMode,
    WritableConfig,
};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
};
use UniMethod\Frame\Integration\{
    ApiService,
    ConstraintCheckerInterface,
    UserProviderInterface,
};
use UniMethod\Frame\Integration\{ApiResult, Errors};
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{
    EntityCollectionInterface,
    EntitySaverInterface,
    ListLocators,
    ListRemovers,
    ListSavers,
};
use UniMethod\Frame\Persistence\EntityLocatorInterface;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Integration\Stub\AccessUser;
use UniMethod\Frame\Type\TypeSpecParser;
use UniMethod\Frame\Validator\ActionValidator;

final class ApiServiceAccessTest extends TestCase
{
    private ApiService $service;

    private ConstraintCheckerInterface&Stub $constraintChecker;

    private EntitySaverInterface&Stub $saver;

    private MappingToObject&Stub $mappingToObject;

    private MappingFromObject&Stub $mappingFromObject;

    private UserProviderInterface&Stub $userProvider;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\AccessUser",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;
                use UniMethod\Frame\Integration\UserInterface;
                class AccessUser implements UserInterface {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        private array $roles = ["user"],
                    ) {}
                    public function getUserIdentifier(): string|int { return $this->id ?? "0"; }
                    public function getRoles(): array { return $this->roles; }
                    public function hasRole(string $role): bool { return in_array($role, $this->roles, true); }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $this->saver = $this->createStub(EntitySaverInterface::class);
        $this->mappingToObject = $this->createStub(MappingToObject::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);
        $this->userProvider = $this->createStub(UserProviderInterface::class);
    }

    private function makeConfigWithAccess(?AccessConfig $access): Config
    {
        return new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    access: $access,
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );
    }

    /**
     * The user is authenticated but without the required role → 403.
     *
     * Пользователь аутентифицирован, но без нужной роли → 403.
     */
    public function testAccessDeniedReturns403(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["admin"]);
        $config = $this->makeConfigWithAccess($access);

        $userProvider
            ->method("getCurrentUser")
            ->willReturn(new AccessUser("1", "John"));
        $userProvider
            ->expects($this->once())
            ->method("canAccessAction")
            ->willReturn(false);

        $this->service = $this->makeService($config);

        /** @var ApiResult<Errors> $result */
        $result = $this->service->execute(
            "user",
            ActionType::Create,
            rawContent: "{}",
        );

        $this->assertEquals(403, $result->statusCode);
        $this->assertFalse($result->encoded);
        $this->assertStringContainsString(
            "Access denied",
            $result->data->errors[0]->detail,
        );
    }

    public function testAccessGrantedWithValidRole(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["admin"]);
        $config = $this->makeConfigWithAccess($access);

        $userProvider
            ->expects($this->once())
            ->method("canAccessAction")
            ->willReturn(true);

        $entity = new AccessUser("1", "John");
        $this->mappingToObject->method("map")->willReturn($entity);
        $this->constraintChecker->method("check")->willReturn([]);
        $this->saver->method("save")->willReturn($entity);
        $this->mappingFromObject
            ->method("map")
            ->willReturn((object) ["id" => "1", "name" => "John"]);

        $this->service = $this->makeService($config);
        $result = $this->service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"name":"John"}',
        );

        $this->assertEquals(201, $result->statusCode);
        $this->assertTrue($result->encoded);
    }

    public function testNoAccessConfigMeansAllowed(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $config = $this->makeConfigWithAccess(null);

        // userProvider must not be called
        //
        // userProvider не должен вызываться
        $userProvider->expects($this->never())->method("canAccessAction");

        $entity = new AccessUser("1", "Anonymous");
        $this->mappingToObject->method("map")->willReturn($entity);
        $this->constraintChecker->method("check")->willReturn([]);
        $this->saver->method("save")->willReturn($entity);
        $this->mappingFromObject
            ->method("map")
            ->willReturn((object) ["id" => "1", "name" => "Anonymous"]);

        $this->service = $this->makeService($config);
        $result = $this->service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"name":"Anonymous"}',
        );

        $this->assertEquals(201, $result->statusCode);
    }

    /**
     * access.authRequired, and getCurrentUser() === null → 401.
     *
     * access.authRequired, а getCurrentUser() === null → 401.
     */
    public function testAuthRequiredButUserNotLoggedIn(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true);
        $config = $this->makeConfigWithAccess($access);

        // canAccessAction returns false because the user is not authorized
        //
        // canAccessAction возвращает false, т.к. пользователь не авторизован
        $userProvider->method("getCurrentUser")->willReturn(null);
        $userProvider
            ->expects($this->once())
            ->method("canAccessAction")
            ->willReturn(false);

        $this->service = $this->makeService($config);
        $result = $this->service->execute(
            "user",
            ActionType::Create,
            rawContent: "{}",
        );

        $this->assertEquals(401, $result->statusCode);
    }

    /**
     * Without a UserProvider, a non-trivial action access → 401 (fail-closed).
     *
     * Без UserProvider нетривиальный access действия → 401 (fail-closed).
     */
    public function testAccessRestrictedActionWithoutUserProviderReturns401(): void
    {
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;

        $config = $this->makeConfigWithAccess(
            new AccessConfig(authRequired: false, roles: ["admin"]),
        );

        $saver->expects($this->never())->method("save");

        $service = $this->makeService($config, withUserProvider: false);
        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: "{}",
        );

        $this->assertEquals(401, $result->statusCode);
    }

    // =============== includable ===============

    public function testIncludablePathNotInMapIsFilteredOut(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    includable: ["profile" => null],
                    entityLocator: "default",
                ),
            ],
        );

        $locator = $this->createStub(EntityLocatorInterface::class);
        $entity = new AccessUser("1", "John");
        $locator->method("find")->willReturn($entity);
        $listLocators = new ListLocators(["default" => $locator]);

        $capturedInclude = null;
        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $obj,
                array $include,
            ) use (&$capturedInclude): \stdClass {
                $capturedInclude = $include;
                return (object) ["id" => "1"];
            });

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $listLocators,
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $this->userProvider,
        );

        // Request "posts", which is not in includable
        //
        // Запрашиваем "posts" которого нет в includable
        $service->execute(
            "user",
            ActionType::Read,
            ["included" => "posts"],
            id: "1",
        );

        $this->assertNotContains("posts", $capturedInclude);
    }

    public function testIncludablePathWithNullAccessIsIncluded(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    includable: ["profile" => null],
                    entityLocator: "default",
                ),
            ],
        );

        $locator = $this->createStub(EntityLocatorInterface::class);
        $locator->method("find")->willReturn(new AccessUser("1", "John"));
        $listLocators = new ListLocators(["default" => $locator]);

        // canAccess must not be called with a null AccessConfig
        //
        // canAccess не должен вызываться при null AccessConfig
        $userProvider->expects($this->never())->method("canAccess");

        $capturedInclude = null;
        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $obj,
                array $include,
            ) use (&$capturedInclude): \stdClass {
                $capturedInclude = $include;
                return (object) ["id" => "1"];
            });

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $listLocators,
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $userProvider,
        );

        $service->execute(
            "user",
            ActionType::Read,
            ["included" => "profile"],
            id: "1",
        );

        $this->assertContains("profile", $capturedInclude);
    }

    public function testIncludablePathWithAccessConfigGrantedIsIncluded(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    includable: ["adminLogs" => $accessConfig],
                    entityLocator: "default",
                ),
            ],
        );

        $locator = $this->createStub(EntityLocatorInterface::class);
        $locator->method("find")->willReturn(new AccessUser("1", "John"));
        $listLocators = new ListLocators(["default" => $locator]);

        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($accessConfig)
            ->willReturn(true);

        $capturedInclude = null;
        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $obj,
                array $include,
            ) use (&$capturedInclude): \stdClass {
                $capturedInclude = $include;
                return (object) ["id" => "1"];
            });

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $listLocators,
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $userProvider,
        );

        $service->execute(
            "user",
            ActionType::Read,
            ["included" => "adminLogs"],
            id: "1",
        );

        $this->assertContains("adminLogs", $capturedInclude);
    }

    public function testIncludablePathWithAccessConfigDeniedIsFiltered(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    includable: ["adminLogs" => $accessConfig],
                    entityLocator: "default",
                ),
            ],
        );

        $locator = $this->createStub(EntityLocatorInterface::class);
        $locator->method("find")->willReturn(new AccessUser("1", "John"));
        $listLocators = new ListLocators(["default" => $locator]);

        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($accessConfig)
            ->willReturn(false);

        $capturedInclude = null;
        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $obj,
                array $include,
            ) use (&$capturedInclude): \stdClass {
                $capturedInclude = $include;
                return (object) ["id" => "1"];
            });

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $listLocators,
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $userProvider,
        );

        $service->execute(
            "user",
            ActionType::Read,
            ["included" => "adminLogs"],
            id: "1",
        );

        $this->assertNotContains("adminLogs", $capturedInclude);
    }

    public function testIncludableNullMeansNoRestriction(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        // If $action->includable === null — all requested includes pass
        //
        // Если $action->includable === null — все запрошенные инклуды проходят
        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    entityLocator: "default",
                ),
            ],
        );

        $locator = $this->createStub(EntityLocatorInterface::class);
        $locator->method("find")->willReturn(new AccessUser("1", "John"));
        $listLocators = new ListLocators(["default" => $locator]);

        $userProvider->expects($this->never())->method("canAccess");

        $capturedInclude = null;
        $this->mappingFromObject
            ->method("map")
            ->willReturnCallback(function (
                string $type,
                object $obj,
                array $include,
            ) use (&$capturedInclude): \stdClass {
                $capturedInclude = $include;
                return (object) ["id" => "1"];
            });

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $listLocators,
            savers: $this->createStub(ListSavers::class),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $userProvider,
        );

        $service->execute(
            "user",
            ActionType::Read,
            ["included" => "anything"],
            id: "1",
        );

        $this->assertContains("anything", $capturedInclude);
    }

    // =============== writable AccessConfig ===============

    public function testWritableRelationWithAccessConfigDeniedReturns403(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: $accessConfig,
                        ),
                    ],
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        $userProvider
            ->method("getCurrentUser")
            ->willReturn(new AccessUser("1", "John"));
        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($accessConfig)
            ->willReturn(false);

        $service = $this->makeService($config);

        /** @var ApiResult<Errors> $result */
        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"roles": [{"id": "1"}]}',
        );

        $this->assertEquals(403, $result->statusCode);
        $this->assertStringContainsString(
            "roles",
            $result->data->errors[0]->detail,
        );
    }

    /**
     * access of a writable path with authRequired, the user is not authenticated → 401.
     *
     * access writable-пути с authRequired, пользователь не аутентифицирован → 401.
     */
    public function testWritableRelationDeniedForAnonymousReturns401(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: $accessConfig,
                        ),
                    ],
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        $userProvider->method("getCurrentUser")->willReturn(null);
        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($accessConfig)
            ->willReturn(false);
        $saver->expects($this->never())->method("save");

        $service = $this->makeService($config);

        /** @var ApiResult<Errors> $result */
        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"roles": [{"id": "1"}]}',
        );

        $this->assertEquals(401, $result->statusCode);
        $this->assertStringContainsString(
            "roles",
            $result->data->errors[0]->detail,
        );
    }

    /**
     * `null` on a writable path (unlinking) is a write to the path, checked by the same
     * access as an object.
     *
     * `null` на writable-пути (отвязывание) — запись в путь, проверяется тем же
     * access, что и объект.
     */
    public function testNullOnWritablePathIsSubjectToAccess(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: $accessConfig,
                        ),
                    ],
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        $userProvider
            ->method("getCurrentUser")
            ->willReturn(new AccessUser("1", "John"));
        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($accessConfig)
            ->willReturn(false);
        $saver->expects($this->never())->method("save");

        $service = $this->makeService($config);

        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"roles": null}',
        );

        $this->assertEquals(403, $result->statusCode);
    }

    public function testWritableRelationWithAccessConfigGrantedProceeds(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: $accessConfig,
                        ),
                    ],
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($accessConfig)
            ->willReturn(true);

        $entity = new AccessUser("1", "John");
        $this->mappingToObject->method("map")->willReturn($entity);
        $this->constraintChecker->method("check")->willReturn([]);
        $this->saver->method("save")->willReturn($entity);
        $this->mappingFromObject
            ->method("map")
            ->willReturn((object) ["id" => "1", "name" => "John"]);

        $service = $this->makeService($config);

        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"roles": [{"id": "1"}]}',
        );

        $this->assertEquals(201, $result->statusCode);
    }

    public function testWritableRelationKeyAbsentInBodyDoesNotCallCanAccess(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $accessConfig = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                            access: $accessConfig,
                        ),
                    ],
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        // canAccess must not be called if the key is missing from the body
        //
        // canAccess не должен вызываться, если ключ отсутствует в теле
        $userProvider->expects($this->never())->method("canAccess");

        $entity = new AccessUser("1", "John");
        $this->mappingToObject->method("map")->willReturn($entity);
        $this->constraintChecker->method("check")->willReturn([]);
        $this->saver->method("save")->willReturn($entity);
        $this->mappingFromObject
            ->method("map")
            ->willReturn((object) ["id" => "1", "name" => "John"]);

        $service = $this->makeService($config);

        // There is no "roles" in the body
        //
        // В теле нет "roles"
        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"name": "John"}',
        );

        $this->assertEquals(201, $result->statusCode);
    }

    public function testWritableRelationInheritsActionAccessWhenWritableAccessIsNull(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $actionAccess = new AccessConfig(authRequired: true, roles: ["admin"]);

        $config = new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    access: $actionAccess,
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        // canAccessAction is called first (action-level), we return true
        //
        // canAccessAction вызывается первым (action-level), вернём true
        $userProvider->method("canAccessAction")->willReturn(true);
        $userProvider
            ->method("getCurrentUser")
            ->willReturn(new AccessUser("1", "John"));

        // canAccess is called for the writable relation, uses $actionAccess
        //
        // canAccess вызывается для writable relation, использует $actionAccess
        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($actionAccess)
            ->willReturn(false);

        $service = $this->makeService($config);

        $result = $service->execute(
            "user",
            ActionType::Create,
            rawContent: '{"roles": [{"id": "1"}]}',
        );

        $this->assertEquals(403, $result->statusCode);
    }

    // =============== FilterOperatorConfig access ===============

    /**
     * access of a filter operator with authRequired, the user is not authenticated → 401.
     *
     * access оператора фильтра с authRequired, пользователь не аутентифицирован → 401.
     */
    public function testFilterOperatorDeniedForAnonymousReturns401(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["admin"]);
        $config = $this->makeConfigWithOperatorAccess($access);

        $userProvider->method("getCurrentUser")->willReturn(null);
        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($access)
            ->willReturn(false);

        $service = $this->makeService(
            $config,
            new MappingToListAction(new TypeSpecParser(), config: $config),
        );

        $result = $service->execute(
            modelType: "user",
            actionType: ActionType::List,
            queryParams: ["filter" => ["in" => ["name" => ["John"]]]],
        );

        $this->assertEquals(401, $result->statusCode);
    }

    /**
     * The user is authenticated but without the required role for the operator → 403.
     *
     * Пользователь аутентифицирован, но без нужной роли для оператора → 403.
     */
    public function testFilterOperatorDeniedForUserWithoutRoleReturns403(): void
    {
        $userProvider = $this->createMock(UserProviderInterface::class);
        $this->userProvider = $userProvider;

        $access = new AccessConfig(authRequired: true, roles: ["admin"]);
        $config = $this->makeConfigWithOperatorAccess($access);

        $userProvider
            ->method("getCurrentUser")
            ->willReturn(new AccessUser("1", "John"));
        $userProvider
            ->expects($this->once())
            ->method("canAccess")
            ->with($access)
            ->willReturn(false);

        $service = $this->makeService(
            $config,
            new MappingToListAction(new TypeSpecParser(), config: $config),
        );

        $result = $service->execute(
            modelType: "user",
            actionType: ActionType::List,
            queryParams: ["filter" => ["in" => ["name" => ["John"]]]],
        );

        $this->assertEquals(403, $result->statusCode);
    }

    private function makeConfigWithOperatorAccess(AccessConfig $access): Config
    {
        return new Config(
            models: [
                new Model(
                    class: AccessUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::List,
                    model: "user",
                    config: new ListActionConfig(
                        repository: EntityCollectionInterface::class,
                        filters: [
                            new FilterRule(
                                path: "name",
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

    private function makeService(
        Config $config,
        ?MappingToListAction $mappingToListAction = null,
        bool $withUserProvider = true,
    ): ApiService {
        $actionValidator = $this->createStub(ActionValidator::class);
        $actionValidator->method("validateStd");

        return new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $mappingToListAction ?? $this->createStub(MappingToListAction::class),
            actionValidator: $actionValidator,
            locators: $this->createStub(ListLocators::class),
            savers: new ListSavers(["default" => $this->saver]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->createStub(MutatorRegistry::class),
            userProvider: $withUserProvider ? $this->userProvider : null,
        );
    }
}
