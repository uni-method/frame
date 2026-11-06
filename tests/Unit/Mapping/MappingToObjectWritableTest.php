<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\Exception;
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
use UniMethod\Frame\Mapping\{
    AttributeAccessor,
    MappingToObject,
};
use UniMethod\Frame\Mapping\Exception\{
    ActionNotConfiguredException,
    RelationTypeMismatchException,
};
use UniMethod\Frame\Persistence\EntityLocatorContainerInterface;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestArticle,
    TestAuthor,
    TestBook,
    TestCategory,
    TestComment,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingToObjectWritableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TestBook")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestBook {
                    private string $id;
                    private string $title;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setTitle(string $title): void { $this->title = $title; }
                    public function getId(): string { return $this->id; }
                    public function getTitle(): string { return $this->title; }
                }

                class TestCategory {
                    private string $id;
                    private string $name;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                }

                class TestAuthor {
                    private string $id;
                    private string $name;
                    private array $books = [];
                    public function setId(string $id): void { $this->id = $id; }
                    public function setName(string $name): void { $this->name = $name; }
                    public function setBooks(array $books): void { $this->books = $books; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getBooks(): array { return $this->books; }
                }

                class TestComment {
                    private string $id;
                    private string $text;
                    private ?TestAuthor $author = null;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setText(string $text): void { $this->text = $text; }
                    public function setAuthor(TestAuthor $author): void { $this->author = $author; }
                    public function getId(): string { return $this->id; }
                    public function getText(): string { return $this->text; }
                    public function getAuthor(): ?TestAuthor { return $this->author; }
                }

                class TestArticle {
                    private string $id;
                    private string $title;
                    private ?TestAuthor $author = null;
                    private array $comments = [];
                    private ?TestCategory $category = null;
                    public function setId(string $id): void { $this->id = $id; }
                    public function setTitle(string $title): void { $this->title = $title; }
                    public function setAuthor(TestAuthor $author): void { $this->author = $author; }
                    public function setComments(array $comments): void { $this->comments = $comments; }
                    public function addComment(TestComment $comment): void { $this->comments[] = $comment; }
                    public function setCategory(TestCategory $category): void { $this->category = $category; }
                    public function getId(): string { return $this->id; }
                    public function getTitle(): string { return $this->title; }
                    public function getAuthor(): ?TestAuthor { return $this->author; }
                    public function getComments(): array { return $this->comments; }
                    public function getCategory(): ?TestCategory { return $this->category; }
                }
            ');
        }
    }

    public function testMapWithoutWritableParam(): void
    {
        // Without writable — only attributes are mapped
        //
        // Без writable — мапятся только атрибуты
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "manyToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                        new Relation(
                            "category",
                            "manyToOne",
                            "category",
                            setter: "setCategory",
                        ),
                    ],
                ),
                new Model(class: TestAuthor::class, type: "author"),
                new Model(class: TestCategory::class, type: "category"),
            ],
            actions: [new Action(ActionType::Create, "article")],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Test Article",
            "author" => (object) ["id" => "10", "name" => "John"],
            "category" => (object) ["id" => "20", "name" => "Tech"],
        ];

        $result = $mapper->map("article", $source, ActionType::Create);

        $this->assertSame("Test Article", $result->getTitle());
        $this->assertNull($result->getAuthor());
        $this->assertNull($result->getCategory());
    }

    public function testMapWithSingleWritableRelation(): void
    {
        // Only the author relation is allowed
        //
        // Разрешена только связь author
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "manyToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                        new Relation(
                            "category",
                            "manyToOne",
                            "category",
                            setter: "setCategory",
                        ),
                    ],
                ),
                new Model(
                    class: TestAuthor::class,
                    type: "author",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
                new Model(
                    class: TestCategory::class,
                    type: "category",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("name", "string", setter: "setName"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "article",
                    writable: [
                        "author" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "category" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Test Article",
            "author" => (object) ["id" => "10", "name" => "John"],
            "category" => (object) ["id" => "20", "name" => "Tech"],
        ];

        $result = $mapper->map(
            "article",
            $source,
            ActionType::Create,
            "author",
        );

        $this->assertInstanceOf(TestAuthor::class, $result->getAuthor());
        $this->assertSame("John", $result->getAuthor()->getName());
        $this->assertNull($result->getCategory());
    }

    /**
     * @throws ActionNotConfiguredException
     * @throws Exception
     * @throws RelationTypeMismatchException
     */
    public function testMapWithMultipleWritableRelations(): void
    {
        // Both relations are allowed
        //
        // Разрешены обе связи
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "manyToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                        new Relation(
                            "category",
                            "manyToOne",
                            "category",
                            setter: "setCategory",
                        ),
                    ],
                ),
                new Model(class: TestAuthor::class, type: "author"),
                new Model(class: TestCategory::class, type: "category"),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "article",
                    writable: [
                        "author" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "category" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Test Article",
            "author" => (object) ["id" => "10", "name" => "John"],
            "category" => (object) ["id" => "20", "name" => "Tech"],
        ];

        $result = $mapper->map(
            "article",
            $source,
            ActionType::Create,
            "author,category",
        );

        $this->assertInstanceOf(TestAuthor::class, $result->getAuthor());
        $this->assertInstanceOf(TestCategory::class, $result->getCategory());
    }

    public function testMapWithNestedWritableRelations(): void
    {
        // The author relation and the nested author.books are allowed
        //
        // Разрешена связь author и вложенная author.books
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "manyToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                    ],
                ),
                new Model(
                    class: TestAuthor::class,
                    type: "author",
                    relations: [
                        new Relation(
                            "books",
                            "oneToMany",
                            "book",
                            setter: "setBooks",
                        ),
                    ],
                ),
                new Model(
                    class: TestBook::class,
                    type: "book",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "article",
                    writable: [
                        "author" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "author.books" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Test Article",
            "author" => (object) [
                "id" => "10",
                "name" => "John",
                "books" => [
                    (object) ["id" => "100", "title" => "Book 1"],
                    (object) ["id" => "101", "title" => "Book 2"],
                ],
            ],
        ];

        /** @var \UniMethod\Frame\Tests\Unit\Mapping\Stub\TestArticle $result */
        $result = $mapper->map(
            "article",
            $source,
            ActionType::Create,
            "author,author.books",
        );

        $this->assertCount(2, $result->getAuthor()->getBooks());
        $this->assertSame("100", $result->getAuthor()->getBooks()[0]->getId());
        $this->assertSame(
            "Book 1",
            $result->getAuthor()->getBooks()[0]->getTitle(),
        );
        $this->assertSame("101", $result->getAuthor()->getBooks()[1]->getId());
        $this->assertSame(
            "Book 2",
            $result->getAuthor()->getBooks()[1]->getTitle(),
        );
    }

    public function testMapWithArrayRelation(): void
    {
        // The comments relation is allowed
        //
        // Разрешена связь comments
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "comments",
                            "oneToMany",
                            "comment",
                            setter: "setComments",
                            add: "addComment",
                        ),
                    ],
                ),
                new Model(
                    class: TestComment::class,
                    type: "comment",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("text", "string", setter: "setText"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "article",
                    writable: [
                        "comments" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Article with comments",
            "comments" => [
                (object) ["id" => "1", "text" => "Comment 1"],
                (object) ["id" => "2", "text" => "Comment 2"],
            ],
        ];

        $result = $mapper->map(
            "article",
            $source,
            ActionType::Create,
            "comments",
        );

        $this->assertCount(2, $result->getComments());
        $this->assertSame("Comment 1", $result->getComments()[0]->getText());
    }

    public function testMapAttributesNotAffectedByWritable(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "manyToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                    ],
                ),
                new Model(class: TestAuthor::class, type: "author"),
            ],
            actions: [new Action(ActionType::Create, "article")],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Test Article",
            "author" => (object) ["id" => "10", "name" => "John"],
        ];
        $result = $mapper->map("article", $source, ActionType::Create);

        $this->assertSame("Test Article", $result->getTitle());
        $this->assertNull($result->getAuthor());
    }

    public function testMapAllRelationsExplicitly(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: TestArticle::class,
                    type: "article",
                    attributes: [
                        new Attribute("id", "string", setter: "setId"),
                        new Attribute("title", "string", setter: "setTitle"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "manyToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                        new Relation(
                            "category",
                            "manyToOne",
                            "category",
                            setter: "setCategory",
                        ),
                        new Relation(
                            "comments",
                            "oneToMany",
                            "comment",
                            setter: "setComments",
                            add: "addComment",
                        ),
                    ],
                ),
                new Model(class: TestAuthor::class, type: "author"),
                new Model(class: TestCategory::class, type: "category"),
                new Model(class: TestComment::class, type: "comment"),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "article",
                    writable: [
                        "author" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "category" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "comments" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
        $mapper->setConfig($config);

        $source = (object) [
            "id" => "1",
            "title" => "Full Article",
            "author" => (object) ["id" => "10", "name" => "John"],
            "category" => (object) ["id" => "20", "name" => "Tech"],
            "comments" => [(object) ["id" => "1", "text" => "Comment 1"]],
        ];

        $result = $mapper->map(
            "article",
            $source,
            ActionType::Create,
            "author,category,comments",
        );

        $this->assertInstanceOf(TestAuthor::class, $result->getAuthor());
        $this->assertInstanceOf(TestCategory::class, $result->getCategory());
        $this->assertCount(1, $result->getComments());
    }
}
