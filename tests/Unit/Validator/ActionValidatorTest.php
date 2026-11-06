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
use UniMethod\Frame\Mapping\Exception\ActionNotConfiguredException;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\{
    InvalidDataStructureException,
    UnknownPropertyException,
    UnknownRelationException,
    UnwritableRelationException,
};

final class ActionValidatorTest extends TestCase
{
    private ActionValidator $validator;

    private Config $config;

    protected function setUp(): void
    {
        $this->validator = new ActionValidator(new TreeBuilder());

        // Create the configuration
        //
        // Создаем конфигурацию
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

    public function testValidCreateWithWritableRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "name" => "John Doe",
            "profile" => (object) ["bio" => "Software Developer"],
            "roles" => [(object) ["name" => "admin"]],
        ];

        // Must not throw an exception
        //
        // Не должно выбросить исключение
        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testValidCreateWithOnlyAttributes(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "name" => "John Doe",
        ];

        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testValidReadWithIncludedRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // Request inclusion of allowed relations
        //
        // Запрашиваем включение разрешенных связей
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "profile,roles",
        );
    }

    public function testValidReadWithNestedIncludedRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // Request nested relations
        //
        // Запрашиваем вложенные связи
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "posts.tags",
        );
    }

    public function testInvalidReadWithUnknownRelation(): void
    {
        $data = (object) ["id" => "123"];

        $this->expectException(UnknownRelationException::class);
        $this->expectExceptionMessage(
            "Relation 'nonexistent' does not exist in model 'user' (path: 'nonexistent')",
        );

        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "nonexistent",
        );
    }

    public function testActionNotFound(): void
    {
        $data = (object) ["name" => "John"];

        $this->expectException(ActionNotConfiguredException::class);
        $this->expectExceptionMessage(
            "Action 'create' is not configured for model 'post'",
        );

        // There is no defined create action for post
        //
        // Для post нет определенного create action
        $this->validator->validateStd(ActionType::Create, "post", $data);
    }

    public function testValidUpdateWithWritableRelation(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "name" => "Jane Doe",
            "roles" => [(object) ["id" => "123", "name" => "user"]],
        ];

        $this->validator->validateStd(ActionType::Update, "user", $data);
    }

    public function testUnknownFieldThrowsException(): void
    {
        $data = (object) ["foo" => "bar"];
        $this->expectException(UnknownPropertyException::class);
        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testUnwritableRelationThrowsException(): void
    {
        $data = (object) ["posts" => [(object) ["title" => "Test"]]];
        $this->expectException(UnwritableRelationException::class);
        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testInvalidRelationStructureThrowsException(): void
    {
        // must be an array
        //
        // должно быть массив
        $data = (object) ["roles" => (object) ["id" => "r1"]];
        $this->expectException(InvalidDataStructureException::class);
        $this->validator->validateStd(ActionType::Create, "user", $data);
    }

    public function testUnwritableRelationBehaviorDependsOnSetter(): void
    {
        // Create a separate configuration for this test
        //
        // Создаем отдельную конфигурацию для этого теста
        $testConfig = new Config(
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
                        // A relation WITHOUT a setter (read-only)
                        //
                        // Отношение БЕЗ setter (read-only)
                        new Relation(
                            name: "posts",
                            type: "oneToMany",
                            targetModel: "post",
                            getter: "getPosts",
                            // explicitly read-only
                            //
                            // явно read-only
                            setter: false,
                        ),
                        // A relation WITH a setter
                        //
                        // Отношение С setter
                        new Relation(
                            name: "profile",
                            type: "oneToOne",
                            targetModel: "profile",
                            getter: "getProfile",
                            // has a setter
                            //
                            // есть setter
                            setter: "setProfile",
                        ),
                    ],
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
                    relations: [],
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
            ],
            actions: [
                // Create action - both relations are NOT in writable
                //
                // Create action - оба отношения НЕ в writable
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    // empty writable list
                    //
                    // пустой список writable
                    writable: [],
                    include: [],
                ),
            ],
        );

        $testValidator = new ActionValidator(new TreeBuilder());
        $testValidator->setConfig($testConfig);

        // Case 1: A relation with setter = false (read-only) + an empty array
        // An empty array for a collection is always valid, so the mode check does not trigger
        // Must NOT throw UnwritableRelationException thanks to the condition && $relation->setter !== false
        //
        // Случай 1: Отношение с setter = false (read-only) + пустой массив
        // Пустой массив для коллекции всегда валиден, поэтому проверка режима не срабатывает
        // НЕ должно выбросить UnwritableRelationException благодаря условию && $relation->setter !== false
        $dataWithReadOnlyRelation = (object) [
            "name" => "John Doe",
            // empty array - valid for collections
            //
            // пустой массив - валиден для коллекций
            "posts" => [],
        ];

        try {
            $testValidator->validateStd(
                ActionType::Create,
                "user",
                $dataWithReadOnlyRelation,
            );
        } catch (UnwritableRelationException $unwritableRelationException) {
            $this->fail(
                "Should not throw UnwritableRelationException for relation with setter=false",
            );
        }

        // Case 2: A relation with setter !== false + not in writable
        // MUST throw UnwritableRelationException
        //
        // Случай 2: Отношение с setter !== false + не в writable
        // ДОЛЖНО выбросить UnwritableRelationException
        $dataWithWritableRelation = (object) [
            "name" => "Jane Doe",
            // profile with a setter, but not in writable
            //
            // profile с setter, но не в writable
            "profile" => (object) ["bio" => "Developer"],
        ];

        $this->expectException(UnwritableRelationException::class);
        $testValidator->validateStd(
            ActionType::Create,
            "user",
            $dataWithWritableRelation,
        );
    }

    public function testValidCreateWithWritableConfigAndIncludedRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) [
            "name" => "John Doe",
            "profile" => (object) ["bio" => "Software Developer"],
            "roles" => [(object) ["name" => "admin"]],
        ];

        $this->validator->validateStd(
            ActionType::Create,
            "user",
            $data,
            "profile,roles",
        );
    }
}
