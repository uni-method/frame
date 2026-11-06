<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

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
use UniMethod\Frame\Mapping\Exception\{
    ActionNotConfiguredException,
    ModelNotFoundException,
};
use UniMethod\Frame\Persistence\{EntityLocatorInterface, LocatorNotConfiguredException};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\{
    InvalidDataStructureException,
    RelationModeNotAllowedException,
    UnknownPropertyException,
    UnwritableRelationException,
};

final class ActionValidatorCompleteTest extends TestCase
{
    private ActionValidator $validator;

    private Config $config;

    protected function setUp(): void
    {
        $this->validator = new ActionValidator(new TreeBuilder());

        $this->config = new Config(
            models: [
                new Model(
                    class: "App\\User",
                    type: "user",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "profile",
                            type: "oneToOne",
                            targetModel: "profile",
                            getter: "getProfile",
                            setter: "setProfile",
                        ),
                        new Relation(
                            name: "posts",
                            type: "oneToMany",
                            targetModel: "post",
                            getter: "getPosts",
                        ),
                        new Relation(
                            name: "roles",
                            type: "manyToMany",
                            targetModel: "role",
                            getter: "getRoles",
                            setter: "setRoles",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Profile",
                    type: "profile",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "bio",
                            type: "string",
                            getter: "getBio",
                            setter: "setBio",
                        ),
                    ],
                    relations: [],
                ),
                new Model(
                    class: "App\\Post",
                    type: "post",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "title",
                            type: "string",
                            getter: "getTitle",
                            setter: "setTitle",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "tags",
                            type: "manyToMany",
                            targetModel: "tag",
                            getter: "getTags",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Tag",
                    type: "tag",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                        ),
                    ],
                    relations: [],
                ),
                new Model(
                    class: "App\\Role",
                    type: "role",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "profile" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                    include: ["profile", "roles"],
                ),
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    include: ["profile", "posts.tags", "roles"],
                ),
                new Action(
                    type: ActionType::Update,
                    model: "user",
                    writable: [
                                "roles" => new WritableConfig(
                                    mode: RelationWriteMode::Create,
                                ),
                            ],
                    include: ["profile", "roles"],
                ),
                new Action(type: ActionType::Delete, model: "user"),
            ],
        );

        $this->validator->setConfig($this->config);
    }

    public function testValidateStdModelNotFound(): void
    {
        $data = (object) [
            "name" => "John Doe",
        ];

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model not found: nonexistent");

        $this->validator->validateStd(ActionType::Create, "nonexistent", $data);
    }

    public function testValidateStdActionNotFound(): void
    {
        $data = (object) [
            "name" => "John Doe",
        ];

        $this->expectException(ActionNotConfiguredException::class);

        // "tag" model exists but has no Create action defined
        //
        // Модель "tag" существует, но у неё не определено действие Create
        $this->validator->validateStd(ActionType::Create, "tag", $data);
    }

    public function testValidateStdBodyUnknownProperty(): void
    {
        $data = (object) [
            "name" => "John Doe",
            "unknown" => "value",
        ];

        $this->expectException(UnknownPropertyException::class);
        $this->expectExceptionMessage(
            "Unknown property 'unknown' for model 'user'",
        );

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testValidateRelationStructureInvalid(): void
    {
        $data = (object) [
            "name" => "John Doe",
            "profile" => [],
        ];

        $this->expectException(InvalidDataStructureException::class);
        $this->expectExceptionMessage(
            "Relation 'profile' expects an object or null.",
        );

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    /**
     * getAllowedModeForPath: an exact match of the path in writable — the mode is applied correctly.
     *
     * getAllowedModeForPath: точное совпадение пути в writable — режим применяется корректно.
     */
    public function testExactPathInWritableAllowsCorrectMode(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "roles" => [(object) ["name" => "admin"]],
        ];

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    /**
     * The mode is not inherited: writable = ["items" => Create] does not allow
     * "items.product" — a nested path must be declared explicitly.
     *
     * Режим не наследуется: writable = ["items" => Create] не разрешает
     * "items.product" — вложенный путь должен быть объявлен явно.
     */
    public function testNestedPathRequiresExplicitWritableEntry(): void
    {

        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "items",
                            type: "oneToMany",
                            targetModel: "orderItem",
                            getter: "getItems",
                            setter: "setItems",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\OrderItem",
                    type: "orderItem",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "quantity",
                            type: "int",
                            getter: "getQuantity",
                            setter: "setQuantity",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "product",
                            type: "manyToOne",
                            targetModel: "product",
                            getter: "getProduct",
                            setter: "setProduct",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Product",
                    type: "product",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                        new Attribute(
                            name: "name",
                            type: "string",
                            getter: "getName",
                            setter: "setName",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                      "items" => new WritableConfig(
                          mode: RelationWriteMode::Create,
                      ),
                    ],
                ),
            ],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        // "items.product" is not declared in writable → 422, even though "items" => Create
        //
        // "items.product" не объявлен в writable → 422, хотя "items" => Create
        $data = (object) [
            "items" => [
                (object) [
                    "quantity" => 2,
                    "product" => (object) ["name" => "Widget"],
                ],
            ],
        ];

        $this->expectException(UnwritableRelationException::class);

        $validator->validateStd(ActionType::Create, "order", $data);
    }

    /**
     * getAllowedModeForPath: the path is missing from writable — an exception is thrown.
     *
     * getAllowedModeForPath: путь отсутствует в writable — бросается исключение.
     */
    public function testPathAbsentFromWritableThrowsException(): void
    {
        // "posts" is not specified in writable of the Create action for "user"
        //
        // "posts" не указан в writable Create action для "user"
        $data = (object) ["posts" => [(object) ["title" => "Test"]]];

        $this->expectException(UnwritableRelationException::class);

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    /**
     * detectWriteModeFromPayload: an object with several fields is detected as Create.
     *
     * detectWriteModeFromPayload: объект с несколькими полями определяется как Create.
     */
    public function testMultiFieldPayloadDetectedAsCreateMode(): void
    {
        $this->expectNotToPerformAssertions();

        // roles writable = Create; data with several fields → detected as Create → matches
        //
        // roles writable = Create; данные с несколькими полями → определяется как Create → совпадает
        $data = (object) [
            "roles" => [(object) ["name" => "admin"]],
        ];

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    /**
     * detectWriteModeFromPayload: an object with only id is detected as Reference.
     * If the config expects Create — RelationModeNotAllowedException is thrown.
     *
     * detectWriteModeFromPayload: объект только с id определяется как Reference.
     * Если конфиг ожидает Create — бросается RelationModeNotAllowedException.
     */
    public function testIdOnlyPayloadDetectedAsReferenceModeConflictsWithCreate(): void
    {
        // roles writable = Create; data with only id → detected as Reference ≠ Create → throws
        //
        // roles writable = Create; данные только с id → определяется как Reference ≠ Create → бросает
        $data = (object) [
            "roles" => [(object) ["id" => "1"]],
        ];

        $this->expectException(RelationModeNotAllowedException::class);

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    /**
     * detectWriteModeFromPayload: null is skipped before the mode check — always valid.
     *
     * detectWriteModeFromPayload: null пропускается до проверки режима — всегда валиден.
     */
    public function testNullRelationPayloadIsAlwaysValid(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["profile" => null];

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    /**
     * validateRelationExistenceWithLocators: a correctly configured locator — no exception.
     *
     * validateRelationExistenceWithLocators: корректно сконфигурированный локатор — нет исключения.
     */
    public function testReferenceWithValidLocatorPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "customer",
                            type: "manyToOne",
                            targetModel: "customer",
                            getter: "getCustomer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Customer",
                    type: "customer",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) ["customer" => (object) ["id" => "customer-1"]];

        $validator->validateStd(ActionType::Create, "order", $data);
    }

    /**
     * validateRelationExistenceWithLocators: targetModel not found — ModelNotFoundException.
     *
     * validateRelationExistenceWithLocators: targetModel не найден — ModelNotFoundException.
     */
    public function testReferenceToNonexistentModelThrows(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "ghost",
                            type: "manyToOne",
                            targetModel: "nonexistent",
                            getter: "getGhost",
                            setter: "setGhost",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "ghost" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
            locators: ["default" => EntityLocatorInterface::class],
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) ["ghost" => (object) ["id" => "1"]];

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model not found: nonexistent");

        $validator->validateStd(ActionType::Create, "order", $data);
    }

    /**
     * validateRelationExistenceWithLocators: the locator name is set in the action but missing
     * from Config->locators — LocatorNotConfiguredException.
     *
     * validateRelationExistenceWithLocators: имя локатора задано в action, но отсутствует
     * в Config->locators — LocatorNotConfiguredException.
     */
    public function testReferenceWithUnconfiguredLocatorThrows(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: "App\\Order",
                    type: "order",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "customer",
                            type: "manyToOne",
                            targetModel: "customer",
                            getter: "getCustomer",
                            setter: "setCustomer",
                        ),
                    ],
                ),
                new Model(
                    class: "App\\Customer",
                    type: "customer",
                    attributes: [
                        new Attribute(
                            name: "id",
                            type: "string",
                            getter: "getId",
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "customer", entityLocator: "default"),
                new Action(
                    type: ActionType::Create,
                    model: "order",
                    writable: [
                        "customer" => new WritableConfig(
                            mode: RelationWriteMode::Reference,
                        ),
                    ],
                    entityLocator: "default",
                ),
            ],
            // locators are not configured — "default" is not registered
            //
            // locators не сконфигурированы — "default" не зарегистрирован
        );

        $validator = new ActionValidator(new TreeBuilder());
        $validator->setConfig($config);

        $data = (object) ["customer" => (object) ["id" => "customer-1"]];

        $this->expectException(LocatorNotConfiguredException::class);

        $validator->validateStd(ActionType::Create, "order", $data);
    }
}
