<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use stdClass;
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
use UniMethod\Frame\Mapping\{
    AttributeAccessor,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\{
    ModelNotFoundException,
    RelationTypeMismatchException,
};
use UniMethod\Frame\Persistence\{
    EntityLocatorContainerInterface,
    EntityLocatorInterface,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Rel\{
    TestCompanyStub,
    TestPostStub,
    TestProfileStub,
    TestRoleStub,
    TestUserStub,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingToObjectRelationsTest extends TestCase
{
    private function createTestClasses(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Rel\TestProfileStub",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Rel;

                class TestProfileStub {
                    private string $id;
                    private string $bio;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setBio(string $bio): void { $this->bio = $bio; }
                    public function getId(): string { return $this->id; }
                    public function getBio(): string { return $this->bio; }
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Rel\TestPostStub")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Rel;

                class TestPostStub {
                    private string $id;
                    private string $title;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setTitle(string $title): void { $this->title = $title; }
                    public function getId(): string { return $this->id; }
                    public function getTitle(): string { return $this->title; }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Rel\TestCompanyStub",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Rel;

                class TestCompanyStub {
                    private string $id;
                    private string $name;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Rel\TestRoleStub")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Rel;

                class TestRoleStub {
                    private string $id;
                    private string $name;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Rel;

                class TestUserStub {
                    private string $id;
                    private string $name;
                    private ?TestProfileStub $profile = null;
                    private array $posts = [];
                    private ?TestCompanyStub $company = null;
                    private array $roles = [];

                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function setProfile(TestProfileStub $profile): void { $this->profile = $profile; }
                    public function setPosts(array $posts): void { $this->posts = $posts; }
                    public function addPost(TestPostStub $post): void { $this->posts[] = $post; }
                    public function setCompany(TestCompanyStub $company): void { $this->company = $company; }
                    public function setRoles(array $roles): void { $this->roles = $roles; }
                    public function addRole(TestRoleStub $role): void { $this->roles[] = $role; }

                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getProfile(): ?TestProfileStub { return $this->profile; }
                    public function getPosts(): array { return $this->posts; }
                    public function getCompany(): ?TestCompanyStub { return $this->company; }
                    public function getRoles(): array { return $this->roles; }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->createTestClasses();
    }

    private function makeMapper(
        ?EntityLocatorContainerInterface $locatorContainer = null,
    ): MappingToObject {
        return new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $locatorContainer ??
                $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
    }

    public function testMapOneToOneRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                    relations: [
                        new Relation(
                            "profile",
                            "oneToOne",
                            "profile",
                            setter: "setProfile",
                        ),
                    ],
                ),
                new Model(
                    class: TestProfileStub::class,
                    type: "profile",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("bio", "string", setter: "setBio"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "profile" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "name" => "John Doe",
            "profile" => (object) [
                "id" => "10",
                "bio" => "Software developer",
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create, "profile");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertSame("1", $result->getId());
        $this->assertSame("John Doe", $result->getName());
        $this->assertInstanceOf(TestProfileStub::class, $result->getProfile());
        $this->assertSame("10", $result->getProfile()->getId());
        $this->assertSame(
            "Software developer",
            $result->getProfile()->getBio(),
        );
    }

    public function testMapOneToManyRelationWithSetter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                        ),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "posts" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "posts" => [
                (object) ["id" => "100", "title" => "First post"],
                (object) ["id" => "101", "title" => "Second post"],
                (object) ["id" => "102", "title" => "Third post"],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create, "posts");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertCount(3, $result->getPosts());
        $this->assertInstanceOf(TestPostStub::class, $result->getPosts()[0]);
        $this->assertSame("100", $result->getPosts()[0]->getId());
        $this->assertSame("First post", $result->getPosts()[0]->getTitle());
        $this->assertSame("102", $result->getPosts()[2]->getId());
    }

    public function testMapOneToManyRelationWithAddMethod(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            add: "addPost",
                        ),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "posts" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "posts" => [
                (object) ["id" => "100", "title" => "First post"],
                (object) ["id" => "101", "title" => "Second post"],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create, "posts");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertCount(2, $result->getPosts());
        $this->assertSame("100", $result->getPosts()[0]->getId());
        $this->assertSame("101", $result->getPosts()[1]->getId());
    }

    public function testMapOneToManyRelationWithBothSetterAndAdd(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                            add: "addPost",
                        ),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "posts" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "posts" => [(object) ["id" => "100", "title" => "Post 1"]],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create, "posts");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertCount(1, $result->getPosts());
    }

    public function testMapManyToOneRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestCompanyStub::class,
                    type: "company",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "company" => (object) [
                "id" => "50",
                "name" => "Acme Corp",
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create, "company");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertInstanceOf(TestCompanyStub::class, $result->getCompany());
        $this->assertSame("50", $result->getCompany()->getId());
        $this->assertSame("Acme Corp", $result->getCompany()->getName());
    }

    public function testMapManyToManyRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "roles",
                            "manyToMany",
                            "role",
                            setter: "setRoles",
                            add: "addRole",
                        ),
                    ],
                ),
                new Model(
                    class: TestRoleStub::class,
                    type: "role",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "roles" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "roles" => [
                (object) ["id" => "10", "name" => "admin"],
                (object) ["id" => "11", "name" => "editor"],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create, "roles");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertCount(2, $result->getRoles());
        $this->assertInstanceOf(TestRoleStub::class, $result->getRoles()[0]);
        $this->assertSame("admin", $result->getRoles()[0]->getName());
    }

    public function testMapWithNullRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "profile",
                            "oneToOne",
                            "profile",
                            setter: "setProfile",
                        ),
                    ],
                ),
                new Model(
                    class: TestProfileStub::class,
                    type: "profile",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "user")],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "profile" => null,
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create);

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertNull($result->getProfile());
    }

    public function testMapWithMissingRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "profile",
                            "oneToOne",
                            "profile",
                            setter: "setProfile",
                        ),
                    ],
                ),
                new Model(
                    class: TestProfileStub::class,
                    type: "profile",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "user")],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create);

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertNull($result->getProfile());
    }

    public function testMapWithEmptyArrayRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                        ),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "user")],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "posts" => [],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create);

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertCount(0, $result->getPosts());
    }

    public function testThrowsModelNotFoundExceptionForMissingTargetModel(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "profile",
                            "oneToOne",
                            "non_existing_model",
                            setter: "setProfile",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "profile" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "profile" => new stdClass(),
        ];

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model not found: non_existing_model");

        $mapper->map("user", $source, ActionType::Create, "profile");
    }

    public function testThrowsRelationTypeMismatchExceptionForObjectInsteadOfArray(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                        ),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "posts" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "posts" => new stdClass(),
        ];

        $this->expectException(RelationTypeMismatchException::class);
        $this->expectExceptionMessage(
            "Type mismatch in model 'post', relation 'posts': expected array, got object",
        );

        $mapper->map("user", $source, ActionType::Create, "posts");
    }

    public function testThrowsRelationTypeMismatchExceptionForArrayInsteadOfObject(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "profile",
                            "oneToOne",
                            "profile",
                            setter: "setProfile",
                        ),
                    ],
                ),
                new Model(
                    class: TestProfileStub::class,
                    type: "profile",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "profile" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "profile" => [],
        ];

        $this->expectException(RelationTypeMismatchException::class);
        $this->expectExceptionMessage(
            "Type mismatch in model 'profile', relation 'profile': expected object, got array",
        );

        $mapper->map("user", $source, ActionType::Create, "profile");
    }

    public function testThrowsRelationTypeMismatchExceptionForScalarInArrayRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                        ),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "posts" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "posts" => ["not an object", "another string"],
        ];

        $this->expectException(RelationTypeMismatchException::class);
        $this->expectExceptionMessage(
            "Type mismatch in model 'post', relation 'posts': expected object, got string",
        );

        $mapper->map("user", $source, ActionType::Create, "posts");
    }

    public function testMapComplexNestedRelations(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                    relations: [
                        new Relation(
                            "profile",
                            "oneToOne",
                            "profile",
                            setter: "setProfile",
                        ),
                        new Relation(
                            "posts",
                            "oneToMany",
                            "post",
                            setter: "setPosts",
                        ),
                        new Relation(
                            "company",
                            "manyToOne",
                            "company",
                            setter: "setCompany",
                        ),
                    ],
                ),
                new Model(
                    class: TestProfileStub::class,
                    type: "profile",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("bio", "string", setter: "setBio"),
                    ],
                ),
                new Model(
                    class: TestPostStub::class,
                    type: "post",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                ),
                new Model(
                    class: TestCompanyStub::class,
                    type: "company",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "user",
                    writable: [
                        "profile" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "posts" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "company" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "name" => "John Doe",
            "profile" => (object) ["id" => "10", "bio" => "Developer"],
            "posts" => [
                (object) ["id" => "100", "title" => "Post 1"],
                (object) ["id" => "101", "title" => "Post 2"],
            ],
            "company" => (object) ["id" => "50", "name" => "Acme"],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map(
            "user",
            $source,
            ActionType::Create,
            "profile,posts,company",
        );

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertSame("John Doe", $result->getName());
        $this->assertInstanceOf(TestProfileStub::class, $result->getProfile());
        $this->assertSame("Developer", $result->getProfile()->getBio());
        $this->assertCount(2, $result->getPosts());
        $this->assertSame("Post 1", $result->getPosts()[0]->getTitle());
        $this->assertInstanceOf(TestCompanyStub::class, $result->getCompany());
        $this->assertSame("Acme", $result->getCompany()->getName());
    }

    public function testMapWithRelationWithoutSetter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                    relations: [new Relation("profile", "oneToOne", "profile")],
                ),
                new Model(
                    class: TestProfileStub::class,
                    type: "profile",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "user")],
        );

        $mapper = $this->makeMapper();
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "profile" => (object) ["id" => "10"],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Create);

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertNull($result->getProfile());
    }

    public function testLocatorReceivesObjectOnUpdate(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestUserStub::class,
                    type: "user",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                    relations: [],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Update,
                    "user",
                    entityLocator: "userLocator",
                ),
            ],
        );

        $existingUser = new TestUserStub();
        $existingUser->setId("1");
        $existingUser->setName("Old Name");

        $source = (object) [
            "id" => "1",
            "name" => "New Name",
        ];

        $locator = $this->createMock(EntityLocatorInterface::class);
        $locator
            ->expects($this->once())
            ->method("find")
            ->with(TestUserStub::class, "1", $this->anything(), $source)
            ->willReturn($existingUser);

        $locatorContainer = $this->createMock(
            EntityLocatorContainerInterface::class,
        );
        $locatorContainer
            ->method("getEntityLocatorByName")
            ->with("userLocator")
            ->willReturn($locator);

        $mapper = $this->makeMapper($locatorContainer);
        $mapper->setConfig($config);

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Rel\TestUserStub $result */
        $result = $mapper->map("user", $source, ActionType::Update, null, "1");

        $this->assertInstanceOf(TestUserStub::class, $result);
        $this->assertSame("New Name", $result->getName());
    }
}
