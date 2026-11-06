<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mutator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    ActionType,
    Attribute,
    Config,
    Model,
    Mutators,
    PhaseType,
    Relation,
};
use UniMethod\Frame\Mapping\AttributeAccessor;
use UniMethod\Frame\Mutator\{AttributeMutatorInterface, MutatorRegistry};

/**
 * Tests of recursive traversal of relations in MutatorRegistry
 *
 * Тесты рекурсивного обхода relations в MutatorRegistry
 */
final class MutatorRegistryRecursionTest extends TestCase
{
    /**
     * Test: mutators are applied to a OneToOne relation
     *
     * Тест: мутаторы применяются к OneToOne relation
     */
    public function testApplyPhaseRecursivelyHandlesOneToOneRelation(): void
    {
        // Arrange - User entity
        //
        // Arrange - User сущность
        $user = new class () {
            public string $password = "plaintext";

            public function getPassword(): string
            {
                return $this->password;
            }

            public function setPassword(string $value): void
            {
                $this->password = $value;
            }
        };

        // Login entity with a relation to User
        //
        // Login сущность с relation на User
        $login = new class ($user) {
            public string $token = "abc123";

            public object $user;

            public function __construct(object $user)
            {
                $this->user = $user;
            }

            public function getUser(): object
            {
                return $this->user;
            }
        };

        // Mock of the mutator for password
        //
        // Mock мутатора для password
        $hideMutator = $this->createMock(AttributeMutatorInterface::class);
        $hideMutator
            ->expects($this->once())
            ->method("apply")
            ->with(
                $this->equalTo(PhaseType::BeforeOutput),
                $this->identicalTo($user),
                $this->isInstanceOf(Model::class),
                $this->equalTo("password"),
                $this->equalTo("plaintext"),
                $this->equalTo(ActionType::Read),
            )
            ->willReturn("***");

        $registry = new MutatorRegistry(
            ["hide" => $hideMutator],
            new AttributeAccessor(),
        );

        // Models
        //
        // Модели
        $userModel = new Model(
            class: get_class($user),
            type: "user",
            attributes: [
                new Attribute(
                    name: "password",
                    type: "string",
                    getter: "getPassword",
                    setter: "setPassword",
                    mutators: new Mutators(beforeOutput: ["hide"]),
                ),
            ],
        );

        $loginModel = new Model(
            class: get_class($login),
            type: "login",
            attributes: [
                new Attribute(
                    name: "token",
                    type: "string",
                    getter: "token",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "user",
                    getter: "getUser",
                ),
            ],
        );

        $config = new Config(models: [$loginModel, $userModel]);
        $registry->setConfig($config);

        // Include tree: ['user' => []]
        //
        // Дерево include: ['user' => []]
        $includeTree = ["user" => []];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $login,
            $loginModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert
        //
        // Проверка
        $this->assertSame(
            "***",
            $user->password,
            "Mutator should be applied to nested user.password",
        );
    }

    /**
     * Test: mutators are applied to a OneToMany relation
     *
     * Тест: мутаторы применяются к OneToMany relation
     */
    public function testApplyPhaseRecursivelyHandlesOneToManyRelation(): void
    {
        // Arrange - Comment entities
        //
        // Arrange - Comment сущности
        $comment1 = new class () {
            public string $text = "spam comment";

            public function getText(): string
            {
                return $this->text;
            }

            public function setText(string $value): void
            {
                $this->text = $value;
            }
        };

        $comment2 = new class () {
            public string $text = "another spam";

            public function getText(): string
            {
                return $this->text;
            }

            public function setText(string $value): void
            {
                $this->text = $value;
            }
        };

        // Post with a collection of Comments
        //
        // Post с коллекцией Comments
        $post = new class ($comment1, $comment2) {
            public string $title = "Post Title";

            /** @var list<object> */
            public array $comments;

            public function __construct(object $c1, object $c2)
            {
                $this->comments = [$c1, $c2];
            }

            /** @return list<object> */
            public function getComments(): array
            {
                return $this->comments;
            }
        };

        // Mutator mock
        //
        // Mock мутатора
        $filterMutator = $this->createMock(AttributeMutatorInterface::class);
        $filterMutator
            ->expects($this->exactly(2))
            ->method("apply")
            ->willReturn("[filtered]");

        $registry = new MutatorRegistry(
            ["filter" => $filterMutator],
            new AttributeAccessor(),
        );

        // Models
        //
        // Модели
        $commentModel = new Model(
            class: get_class($comment1),
            type: "comment",
            attributes: [
                new Attribute(
                    name: "text",
                    type: "string",
                    getter: "getText",
                    setter: "setText",
                    mutators: new Mutators(beforeOutput: ["filter"]),
                ),
            ],
        );

        $postModel = new Model(
            class: get_class($post),
            type: "post",
            attributes: [
                new Attribute(
                    name: "title",
                    type: "string",
                    getter: "title",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "comments",
                    type: Relation::ONE_TO_MANY,
                    targetModel: "comment",
                    getter: "getComments",
                ),
            ],
        );

        $config = new Config(models: [$postModel, $commentModel]);
        $registry->setConfig($config);

        // Include tree: ['comments' => []]
        //
        // Дерево include: ['comments' => []]
        $includeTree = ["comments" => []];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $post,
            $postModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert
        //
        // Проверка
        $this->assertSame("[filtered]", $comment1->text);
        $this->assertSame("[filtered]", $comment2->text);
    }

    /**
     * Test: deep recursion (login.user.profile)
     *
     * Тест: глубокая рекурсия (login.user.profile)
     */
    public function testApplyPhaseHandlesDeepNestedRelations(): void
    {
        // Arrange - Profile
        //
        // Подготовка - Profile
        $profile = new class () {
            public string $bio = "My biography";

            public function getBio(): string
            {
                return $this->bio;
            }

            public function setBio(string $value): void
            {
                $this->bio = $value;
            }
        };

        // User
        $user = new class ($profile) {
            public string $email = "user@example.com";

            public object $profile;

            public function __construct(object $profile)
            {
                $this->profile = $profile;
            }

            public function getProfile(): object
            {
                return $this->profile;
            }
        };

        // Login
        $login = new class ($user) {
            public string $token = "token123";

            public object $user;

            public function __construct(object $user)
            {
                $this->user = $user;
            }

            public function getUser(): object
            {
                return $this->user;
            }
        };

        // Mutator mock
        //
        // Mock мутатора
        $truncateMutator = $this->createMock(AttributeMutatorInterface::class);
        $truncateMutator
            ->expects($this->once())
            ->method("apply")
            ->willReturn("My bio...");

        $registry = new MutatorRegistry(
            ["truncate" => $truncateMutator],
            new AttributeAccessor(),
        );

        // Models
        //
        // Модели
        $profileModel = new Model(
            class: get_class($profile),
            type: "profile",
            attributes: [
                new Attribute(
                    name: "bio",
                    type: "string",
                    getter: "getBio",
                    setter: "setBio",
                    mutators: new Mutators(beforeOutput: ["truncate"]),
                ),
            ],
        );

        $userModel = new Model(
            class: get_class($user),
            type: "user",
            attributes: [
                new Attribute(
                    name: "email",
                    type: "string",
                    getter: "email",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "profile",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "profile",
                    getter: "getProfile",
                ),
            ],
        );

        $loginModel = new Model(
            class: get_class($login),
            type: "login",
            attributes: [
                new Attribute(
                    name: "token",
                    type: "string",
                    getter: "token",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "user",
                    getter: "getUser",
                ),
            ],
        );

        $config = new Config(models: [$loginModel, $userModel, $profileModel]);
        $registry->setConfig($config);

        // Include tree: ['user' => ['profile' => []]]
        //
        // Дерево include: ['user' => ['profile' => []]]
        $includeTree = [
            "user" => [
                "profile" => [],
            ],
        ];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $login,
            $loginModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert
        //
        // Проверка
        $this->assertSame(
            "My bio...",
            $profile->bio,
            "Mutator should be applied at depth 2",
        );
    }

    /**
     * Test: relations not in includeTree are skipped
     *
     * Тест: relations не в includeTree пропускаются
     */
    public function testApplyPhaseSkipsRelationsNotInIncludeTree(): void
    {
        // Arrange
        //
        // Подготовка
        $user = new class () {
            public string $password = "secret123";

            public function getPassword(): string
            {
                return $this->password;
            }

            public function setPassword(string $value): void
            {
                $this->password = $value;
            }
        };

        $login = new class ($user) {
            public string $token = "abc";

            public object $user;

            public function __construct(object $user)
            {
                $this->user = $user;
            }

            public function getUser(): object
            {
                return $this->user;
            }
        };

        // The mutator must NOT be called
        //
        // Мутатор НЕ должен вызваться
        $hideMutator = $this->createMock(AttributeMutatorInterface::class);
        $hideMutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["hide" => $hideMutator],
            new AttributeAccessor(),
        );

        $userModel = new Model(
            class: get_class($user),
            type: "user",
            attributes: [
                new Attribute(
                    name: "password",
                    type: "string",
                    getter: "getPassword",
                    setter: "setPassword",
                    mutators: new Mutators(beforeOutput: ["hide"]),
                ),
            ],
        );

        $loginModel = new Model(
            class: get_class($login),
            type: "login",
            attributes: [
                new Attribute(
                    name: "token",
                    type: "string",
                    getter: "token",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "user",
                    getter: "getUser",
                ),
            ],
        );

        $config = new Config(models: [$loginModel, $userModel]);
        $registry->setConfig($config);

        // Empty includeTree - 'user' is NOT included
        //
        // Empty includeTree - 'user' НЕ включён
        $includeTree = [];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $login,
            $loginModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert
        //
        // Проверка
        $this->assertSame(
            "secret123",
            $user->password,
            "Password should NOT be mutated",
        );
    }

    /**
     * Test: a null value in a relation is skipped
     *
     * Тест: null значение в relation пропускается
     */
    public function testApplyPhaseSkipsNullRelations(): void
    {
        // Arrange
        //
        // Подготовка
        $login = new class () {
            public string $token = "abc";

            public ?object $user = null;

            public function getUser(): ?object
            {
                return $this->user;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["test" => $mutator],
            new AttributeAccessor(),
        );

        $userModel = new Model(
            class: "UserClass",
            type: "user",
            attributes: [
                new Attribute(
                    name: "email",
                    type: "string",
                    getter: "email",
                    setter: false,
                    mutators: new Mutators(beforeOutput: ["test"]),
                ),
            ],
        );

        $loginModel = new Model(
            class: get_class($login),
            type: "login",
            attributes: [
                new Attribute(
                    name: "token",
                    type: "string",
                    getter: "token",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "user",
                    getter: "getUser",
                ),
            ],
        );

        $config = new Config(models: [$loginModel, $userModel]);
        $registry->setConfig($config);

        $includeTree = ["user" => []];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $login,
            $loginModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert - no exception, the mutator was not called
        //
        // Assert - no exception, мутатор не вызван
        $this->assertNull($login->user);
    }

    /**
     * Test: an empty collection in OneToMany is skipped
     *
     * Тест: пустая коллекция в OneToMany пропускается
     */
    public function testApplyPhaseHandlesEmptyOneToManyCollection(): void
    {
        // Arrange
        //
        // Подготовка
        $post = new class () {
            public string $title = "Empty Post";

            /** @var list<object> */
            public array $comments = [];

            /** @return list<object> */
            public function getComments(): array
            {
                return $this->comments;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["filter" => $mutator],
            new AttributeAccessor(),
        );

        $commentModel = new Model(
            class: "CommentClass",
            type: "comment",
            attributes: [
                new Attribute(
                    name: "text",
                    type: "string",
                    getter: "text",
                    setter: false,
                    mutators: new Mutators(beforeOutput: ["filter"]),
                ),
            ],
        );

        $postModel = new Model(
            class: get_class($post),
            type: "post",
            attributes: [
                new Attribute(
                    name: "title",
                    type: "string",
                    getter: "title",
                    setter: false,
                ),
            ],
            relations: [
                new Relation(
                    name: "comments",
                    type: Relation::ONE_TO_MANY,
                    targetModel: "comment",
                    getter: "getComments",
                ),
            ],
        );

        $config = new Config(models: [$postModel, $commentModel]);
        $registry->setConfig($config);

        $includeTree = ["comments" => []];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $post,
            $postModel,
            ActionType::List,
            $includeTree,
        );

        // Assert - no exception
        //
        // Проверка - нет исключения
        $this->assertEmpty($post->comments);
    }

    /**
     * Test: applying mutators at different levels simultaneously
     *
     * Тест: применение мутаторов на разных уровнях одновременно
     */
    public function testApplyPhaseMutatesMultipleLevelsSimultaneously(): void
    {
        // Arrange
        //
        // Подготовка
        $user = new class () {
            public string $password = "userpass";

            public function getPassword(): string
            {
                return $this->password;
            }

            public function setPassword(string $value): void
            {
                $this->password = $value;
            }
        };

        $login = new class ($user) {
            public string $password = "loginpass";

            public object $user;

            public function __construct(object $user)
            {
                $this->user = $user;
            }

            public function getPassword(): string
            {
                return $this->password;
            }

            public function setPassword(string $value): void
            {
                $this->password = $value;
            }

            public function getUser(): object
            {
                return $this->user;
            }
        };

        $hideMutator = $this->createMock(AttributeMutatorInterface::class);
        $hideMutator
            ->expects($this->exactly(2))
            ->method("apply")
            ->willReturn("***");

        $registry = new MutatorRegistry(
            ["hide" => $hideMutator],
            new AttributeAccessor(),
        );

        $userModel = new Model(
            class: get_class($user),
            type: "user",
            attributes: [
                new Attribute(
                    name: "password",
                    type: "string",
                    getter: "getPassword",
                    setter: "setPassword",
                    mutators: new Mutators(beforeOutput: ["hide"]),
                ),
            ],
        );

        $loginModel = new Model(
            class: get_class($login),
            type: "login",
            attributes: [
                new Attribute(
                    name: "password",
                    type: "string",
                    getter: "getPassword",
                    setter: "setPassword",
                    mutators: new Mutators(beforeOutput: ["hide"]),
                ),
            ],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "user",
                    getter: "getUser",
                ),
            ],
        );

        $config = new Config(models: [$loginModel, $userModel]);
        $registry->setConfig($config);

        $includeTree = ["user" => []];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $login,
            $loginModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert
        //
        // Проверка
        $this->assertSame(
            "***",
            $login->password,
            "Login password should be hidden",
        );
        $this->assertSame(
            "***",
            $user->password,
            "User password should be hidden",
        );
    }

    /**
     * Test: a relation without a getter is skipped
     *
     * Тест: relation без getter пропускается
     */
    public function testApplyPhaseSkipsRelationWithoutGetter(): void
    {
        // Arrange
        //
        // Подготовка
        $user = new class () {
            public string $email = "test@example.com";
        };

        $login = new class ($user) {
            public object $user;

            public function __construct(object $user)
            {
                $this->user = $user;
            }
        };

        $mutator = $this->createMock(AttributeMutatorInterface::class);
        $mutator->expects($this->never())->method("apply");

        $registry = new MutatorRegistry(
            ["test" => $mutator],
            new AttributeAccessor(),
        );

        $userModel = new Model(
            class: get_class($user),
            type: "user",
            attributes: [
                new Attribute(
                    name: "email",
                    type: "string",
                    getter: "email",
                    setter: false,
                    mutators: new Mutators(beforeOutput: ["test"]),
                ),
            ],
        );

        $loginModel = new Model(
            class: get_class($login),
            type: "login",
            attributes: [],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::ONE_TO_ONE,
                    targetModel: "user",
                    // Explicitly disabled
                    //
                    // Явно отключён
                    getter: false,
                ),
            ],
        );

        $config = new Config(models: [$loginModel, $userModel]);
        $registry->setConfig($config);

        $includeTree = ["user" => []];

        // Act
        //
        // Действие
        $registry->applyPhase(
            PhaseType::BeforeOutput,
            $login,
            $loginModel,
            ActionType::Read,
            $includeTree,
        );

        // Assert - the mutator was not applied
        //
        // Assert - мутатор не был применён
        $this->assertSame("test@example.com", $user->email);
    }
}
