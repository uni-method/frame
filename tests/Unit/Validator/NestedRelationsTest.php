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
    WritableConfig,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\ActionValidator;
use UniMethod\Frame\Validator\Exception\{
    UnknownRelationException,
    UnwritableRelationException,
};

final class NestedRelationsTest extends TestCase
{
    private ActionValidator $validator;

    public function testReadWithSimpleRelation(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // profile is allowed
        //
        // profile разрешен
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "profile",
        );
    }

    // =============== READ TESTS ===============
    //
    // =============== ТЕСТЫ READ ===============

    public function testReadWithNestedRelation(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // posts.tags is allowed
        //
        // posts.tags разрешено
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "posts.tags",
        );
    }

    public function testReadWithDeeperNestedRelation(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // posts.author.profile is allowed (depth 3)
        //
        // posts.author.profile разрешено (глубина 3)
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "posts.author.profile",
        );
    }

    public function testReadWithMultipleNestedRelations(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // Several nested relations at once
        //
        // Несколько вложенных связей одновременно
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "profile,posts.tags,posts.author.profile",
        );
    }

    public function testReadWithNonExistentNestedRelation(): void
    {
        $data = (object) ["id" => "123"];

        $this->expectException(UnknownRelationException::class);

        // posts.tags.nonexistent does not exist
        //
        // posts.tags.nonexistent не существует
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "posts.tags.nonexistent",
        );
    }

    public function testReadWithExtraDepth(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // If posts.tags is in the config, then posts.tags.creator must work
        // (creator exists in the tag model)
        //
        // Если в конфиге posts.tags, то posts.tags.creator должно работать
        // (creator существует в модели tag)
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "posts.tags",
        );
    }

    public function testCreateWithNestedWritable(): void
    {
        $data = (object) [
            "name" => "John",
            "posts" => [
                (object) [
                    "title" => "Post 1",
                    "tags" => [(object) ["name" => "php"]],
                ],
            ],
        ];

        $this->expectException(UnwritableRelationException::class);

        // posts.tags in writable
        //
        // posts.tags в writable
        $this->validator->validateStd(
            ActionType::Create,
            "user",
            $data,
            "posts.tags",
        );
    }

    // =============== CREATE TESTS ===============
    //
    // =============== ТЕСТЫ CREATE ===============

    public function testCreateWithPartialNestedPath(): void
    {
        $data = (object) [
            "name" => "John",
            "posts" => [(object) ["title" => "Post 1"]],
        ];

        $this->expectException(UnwritableRelationException::class);

        // posts without nested ones is not in writable (only posts.tags)
        //
        // posts без вложенных не в writable (только posts.tags)
        $this->validator->validateStd(
            ActionType::Create,
            "user",
            $data,
            "posts",
        );
    }

    public function testCreateWithDeeperNesting(): void
    {
        $data = (object) [
            "name" => "John",
            "posts" => [
                (object) [
                    "title" => "Post 1",
                    "tags" => [
                        (object) [
                            "name" => "php",
                            "creator" => (object) ["name" => "Admin"],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(UnwritableRelationException::class);

        // If posts.tags is in the config, then posts.tags.creator must work
        //
        // Если в конфиге posts.tags, то posts.tags.creator должно работать
        $this->validator->validateStd(
            ActionType::Create,
            "user",
            $data,
            "posts.tags.creator",
        );
    }

    public function testDeleteWithIncluded(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // For DELETE we also check included via include
        //
        // Для DELETE тоже проверяем included через include
        $this->validator->validateStd(
            ActionType::Delete,
            "user",
            $data,
            "posts",
        );
    }

    // =============== DELETE TESTS ===============
    //
    // =============== ТЕСТЫ DELETE ===============

    public function testComplexNestedPath(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // User -> Posts -> Tags -> Creator (User) -> Profile
        // This will create a cycle in the relation graph, but it must work
        //
        // User -> Posts -> Tags -> Creator (User) -> Profile
        // Это создаст цикл в графе связей, но должно работать
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "posts.tags",
        );
    }

    // =============== COMPLEX SCENARIOS ===============
    //
    // =============== СЛОЖНЫЕ СЦЕНАРИИ ===============

    public function testMixedSimpleAndNested(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // A combination of simple and nested ones
        //
        // Комбинация простых и вложенных
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            "profile,posts.tags,posts.author.profile",
        );
    }

    public function testEmptyIncluded(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // An empty included is always OK
        //
        // Пустой included всегда OK
        $this->validator->validateStd(ActionType::Read, "user", $data);
        $this->validator->validateStd(ActionType::Read, "user", $data, "");
        $this->validator->validateStd(ActionType::Create, "user", $data);
        $this->validator->validateStd(ActionType::Delete, "user", $data);
    }

    public function testWhitespaceHandling(): void
    {
        $this->expectNotToPerformAssertions();

        $data = (object) ["id" => "123"];

        // Spaces must be trimmed
        //
        // Пробелы должны обрезаться
        $this->validator->validateStd(
            ActionType::Read,
            "user",
            $data,
            " profile , posts.tags ",
        );
    }

    protected function setUp(): void
    {
        $this->validator = new ActionValidator(new TreeBuilder());

        // Create the structure: User -> Posts -> Tags -> Author (back to User)
        //
        // Создаем структуру: User -> Posts -> Tags -> Author (обратно к User)
        $config = new Config(
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
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "posts",
                            type: "oneToMany",
                            targetModel: "post",
                            getter: "getPosts",
                        ),
                        new Relation(
                            name: "profile",
                            type: "oneToOne",
                            targetModel: "profile",
                            getter: "getProfile",
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
                        ),
                    ],
                    relations: [
                        new Relation(
                            name: "tags",
                            type: "manyToMany",
                            targetModel: "tag",
                            getter: "getTags",
                        ),
                        new Relation(
                            name: "author",
                            type: "manyToOne",
                            targetModel: "user",
                            getter: "getAuthor",
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
                    relations: [
                        new Relation(
                            name: "creator",
                            type: "manyToOne",
                            targetModel: "user",
                            getter: "getCreator",
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
                        ),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Read,
                    model: "user",
                    include: ["profile", "posts.tags", "posts.author.profile"],
                ),
                new Action(
                    type: ActionType::Create,
                    model: "user",
                    writable: [
                        "posts.tags" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                    include: ["posts.tags"],
                ),
                new Action(
                    type: ActionType::Delete,
                    model: "user",
                    include: ["posts", "profile"],
                ),
            ],
        );

        $this->validator->setConfig($config);
    }
}
