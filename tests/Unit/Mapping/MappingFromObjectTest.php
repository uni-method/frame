<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{Attribute, Config, Model, Relation};
use UniMethod\Frame\Mapping\Exception\{
    AttributeNotFoundException,
    ModelNotFoundException,
};
use UniMethod\Frame\Mapping\MappingFromObject;
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    AuthorForRead,
    CategoryForRead,
    EntityWithPropertyGetter,
    EntityWithoutGetter,
    ProductForRead,
    ReviewForRead,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingFromObjectTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\ProductForRead")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                use DateTimeImmutable;

                class ProductForRead {
                    private string $id;
                    private string $name;
                    private int $quantity;
                    private float $price;
                    private bool $available;
                    private DateTimeImmutable $createdAt;
                    private ?string $description = null;
                    /** @var string[] */
                    private array $tags = [];
                    private ?CategoryForRead $category = null;
                    /** @var ReviewForRead[] */
                    private array $reviews = [];
                    public function __construct(
                        string $id,
                        string $name,
                        int $quantity,
                        float $price,
                        bool $available,
                        DateTimeImmutable $createdAt,
                    ) {
                        $this->id = $id;
                        $this->name = $name;
                        $this->quantity = $quantity;
                        $this->price = $price;
                        $this->available = $available;
                        $this->createdAt = $createdAt;
                    }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getQuantity(): int { return $this->quantity; }
                    public function getPrice(): float { return $this->price; }
                    public function isAvailable(): bool { return $this->available; }
                    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
                    public function getDescription(): ?string { return $this->description; }
                    public function setDescription(?string $description): void { $this->description = $description; }
                    /** @return string[] */
                    public function getTags(): array { return $this->tags; }
                    /** @param string[] $tags */
                    public function setTags(array $tags): void { $this->tags = $tags; }
                    public function getCategory(): ?CategoryForRead { return $this->category; }
                    public function setCategory(?CategoryForRead $category): void { $this->category = $category; }
                    /** @return ReviewForRead[] */
                    public function getReviews(): array { return $this->reviews; }
                    public function addReview(ReviewForRead $review): void { $this->reviews[] = $review; }
                }

                class CategoryForRead {
                    private string $id;
                    private string $name;
                    private ?CategoryForRead $parent = null;
                    /** @var CategoryForRead[] */
                    private array $children = [];
                    public function __construct(string $id, string $name) {
                        $this->id = $id;
                        $this->name = $name;
                    }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getParent(): ?CategoryForRead { return $this->parent; }
                    public function setParent(?CategoryForRead $parent): void { $this->parent = $parent; }
                    /** @return CategoryForRead[] */
                    public function getChildren(): array { return $this->children; }
                    public function addChild(CategoryForRead $child): void { $this->children[] = $child; }
                }

                class ReviewForRead {
                    private string $id;
                    private int $rating;
                    private string $comment;
                    private ?AuthorForRead $author = null;
                    public function __construct(string $id, int $rating, string $comment) {
                        $this->id = $id;
                        $this->rating = $rating;
                        $this->comment = $comment;
                    }
                    public function getId(): string { return $this->id; }
                    public function getRating(): int { return $this->rating; }
                    public function getComment(): string { return $this->comment; }
                    public function getAuthor(): ?AuthorForRead { return $this->author; }
                    public function setAuthor(?AuthorForRead $author): void { $this->author = $author; }
                }

                class AuthorForRead {
                    private string $id;
                    private string $name;
                    public function __construct(string $id, string $name) {
                        $this->id = $id;
                        $this->name = $name;
                    }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                }

                class EntityWithoutGetter {
                    private string $id;
                    public function __construct(string $id) {
                        $this->id = $id;
                    }
                    public function getId(): string { return $this->id; }
                }

                class EntityWithPropertyGetter {
                    public string $id;
                    public string $name;
                    public function __construct(string $id, string $name) {
                        $this->id = $id;
                        $this->name = $name;
                    }
                }
            ');
        }
    }

    public function testMapBasicAttributes(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                    new Attribute("quantity", "int", getter: "getQuantity"),
                    new Attribute("price", "float", getter: "getPrice"),
                    new Attribute("available", "bool", getter: "isAvailable"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Laptop",
            10,
            999.99,
            true,
            new DateTimeImmutable("2025-01-15 10:00:00"),
        );

        $result = $mapper->map("product", $product);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame("1", $result->id);
        $this->assertSame("Laptop", $result->name);
        $this->assertSame(10, $result->quantity);
        $this->assertSame(999.99, $result->price);
        $this->assertTrue($result->available);
    }

    public function testMapDateTimeAttribute(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute(
                        "createdAt",
                        "datetime",
                        getter: "getCreatedAt",
                    ),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $createdAt = new DateTimeImmutable("2025-01-15 10:30:45");
        $product = new ProductForRead("1", "Test", 1, 1.0, true, $createdAt);

        /** @var object{createdAt: DateTimeImmutable} $result */
        $result = $mapper->map("product", $product);

        $this->assertEquals("string", gettype($result->createdAt));
        $this->assertSame("2025-01-15T10:30:45+00:00", $result->createdAt);
    }

    public function testMapNullableAttribute(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute(
                        "description",
                        "string|null",
                        getter: "getDescription",
                    ),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );

        /**
         * @var object{
         *     description: string|null
         * } $result
         */
        $result = $mapper->map("product", $product);

        $this->assertNull($result->description);

        // Now set the value
        //
        // Теперь установим значение
        $product->setDescription("Some description");
        /**
         * @var object{
         *     description: string
         * } $result
         */
        $result = $mapper->map("product", $product);

        $this->assertSame("Some description", $result->description);
    }

    public function testMapArrayAttribute(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("tags", "string[]", getter: "getTags"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );
        $product->setTags(["electronics", "laptop", "gaming"]);

        /**
         * @var object{
         *     tags: array<string>
         * } $result
         */
        $result = $mapper->map("product", $product);

        $this->assertSame(["electronics", "laptop", "gaming"], $result->tags);
    }

    public function testIgnoresAttributesWithoutGetter(): void
    {
        $config = new Config([
            new Model(
                class: EntityWithoutGetter::class,
                type: "entity",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    // No getter
                    //
                    // Нет getter
                    new Attribute("secretField", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $entity = new EntityWithoutGetter("1");

        $result = $mapper->map("entity", $entity);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectNotHasProperty("secretField", $result);
    }

    public function testMapWithPropertyGetter(): void
    {
        $config = new Config([
            new Model(
                class: EntityWithPropertyGetter::class,
                type: "entity",
                attributes: [
                    new Attribute("id", "string", getter: "id"),
                    new Attribute("name", "string", getter: "name"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $entity = new EntityWithPropertyGetter("1", "Test Name");

        /**
         * @var object{
         *     id: string,
         *     name: string
         * } $result
         */
        $result = $mapper->map("entity", $entity);

        $this->assertSame("1", $result->id);
        $this->assertSame("Test Name", $result->name);
    }

    public function testMapOneToOneRelationWithInclude(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "category",
                        getter: "getCategory",
                    ),
                ],
            ),
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $category = new CategoryForRead("10", "Electronics");
        $product = new ProductForRead(
            "1",
            "Laptop",
            5,
            999.99,
            true,
            new DateTimeImmutable(),
        );
        $product->setCategory($category);

        // Without include - the relation must not be included
        //
        // Без include - связь не должна быть включена
        $result = $mapper->map("product", $product);
        $this->assertObjectNotHasProperty("category", $result);

        // With include - the relation must be included
        //
        // С include - связь должна быть включена
        $result = $mapper->map("product", $product, ["category"]);
        $this->assertObjectHasProperty("category", $result);
        $this->assertInstanceOf(stdClass::class, $result->category);
        $this->assertSame("10", $result->category->id);
        $this->assertSame("Electronics", $result->category->name);
    }

    public function testMapOneToManyRelationWithInclude(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
                relations: [
                    new Relation(
                        "reviews",
                        "oneToMany",
                        "review",
                        getter: "getReviews",
                    ),
                ],
            ),
            new Model(
                class: ReviewForRead::class,
                type: "review",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("rating", "int", getter: "getRating"),
                    new Attribute("comment", "string", getter: "getComment"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Laptop",
            5,
            999.99,
            true,
            new DateTimeImmutable(),
        );
        $product->addReview(new ReviewForRead("100", 5, "Excellent!"));
        $product->addReview(new ReviewForRead("101", 4, "Good product"));

        /**
         * @var object{
         *     id: string,
         *     name: string,
         *     reviews: null|array<int, object{
         *         id: string,
         *         rating: int,
         *         comment: string
         *     }>
         * } $result
         */
        $result = $mapper->map("product", $product, ["reviews"]);

        $this->assertIsArray($result->reviews);
        $this->assertCount(2, $result->reviews);
        $this->assertSame("100", $result->reviews[0]->id);
        $this->assertSame(5, $result->reviews[0]->rating);
        $this->assertSame("Excellent!", $result->reviews[0]->comment);
        $this->assertSame("101", $result->reviews[1]->id);
    }

    public function testMapNestedRelationsWithDotNotation(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "reviews",
                        "oneToMany",
                        "review",
                        getter: "getReviews",
                    ),
                ],
            ),
            new Model(
                class: ReviewForRead::class,
                type: "review",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("rating", "int", getter: "getRating"),
                ],
                relations: [
                    new Relation(
                        "author",
                        "manyToOne",
                        "author",
                        getter: "getAuthor",
                    ),
                ],
            ),
            new Model(
                class: AuthorForRead::class,
                type: "author",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $author = new AuthorForRead("50", "John Doe");
        $review = new ReviewForRead("100", 5, "Great!");
        $review->setAuthor($author);

        $product = new ProductForRead(
            "1",
            "Laptop",
            5,
            999.99,
            true,
            new DateTimeImmutable(),
        );
        $product->addReview($review);

        // Include reviews and the nested author
        //
        // Включаем reviews и вложенного author
        $result = $mapper->map("product", $product, [
            "reviews",
            "reviews.author",
        ]);

        $this->assertIsArray($result->reviews);
        $this->assertCount(1, $result->reviews);
        $this->assertObjectHasProperty("author", $result->reviews[0]);
        $this->assertSame("50", $result->reviews[0]->author->id);
        $this->assertSame("John Doe", $result->reviews[0]->author->name);
    }

    public function testMapComplexNestedRelations(): void
    {
        $config = new Config([
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
                relations: [
                    new Relation(
                        "parent",
                        "manyToOne",
                        "category",
                        getter: "getParent",
                    ),
                    new Relation(
                        "children",
                        "oneToMany",
                        "category",
                        getter: "getChildren",
                    ),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $parent = new CategoryForRead("1", "Electronics");
        $child1 = new CategoryForRead("2", "Laptops");
        $child2 = new CategoryForRead("3", "Phones");
        $child1->setParent($parent);
        $child2->setParent($parent);
        $parent->addChild($child1);
        $parent->addChild($child2);

        /**
         * @var object{
         *     id: string,
         *     name: string,
         *     children: array<int, object{
         *         id: string,
         *         name: string,
         *         parent: object{
         *             id: string,
         *             name: string
         *         }
         *     }>|null
         * } $result
         */
        $result = $mapper->map("category", $parent, [
            "children",
            "children.parent",
        ]);

        $this->assertSame("1", $result->id);
        $this->assertSame("Electronics", $result->name);
        $this->assertIsArray($result->children);
        $this->assertCount(2, $result->children);
        $this->assertSame("2", $result->children[0]->id);
        $this->assertObjectHasProperty("parent", $result->children[0]);
        $this->assertSame("1", $result->children[0]->parent->id);
    }

    public function testMapWithNullRelation(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "category",
                        getter: "getCategory",
                    ),
                ],
            ),
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [new Attribute("id", "string", getter: "getId")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );

        /**
         * @var object{
         *     category: stdClass|null
         * } $result
         */
        $result = $mapper->map("product", $product, ["category"]);

        $this->assertNull($result->category);
    }

    public function testMapWithEmptyArrayRelation(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "reviews",
                        "oneToMany",
                        "review",
                        getter: "getReviews",
                    ),
                ],
            ),
            new Model(
                class: ReviewForRead::class,
                type: "review",
                attributes: [new Attribute("id", "string", getter: "getId")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );

        /**
         * @var object{
         *     reviews: array<int>|null
         * } $result
         */
        $result = $mapper->map("product", $product, ["reviews"]);

        $this->assertIsArray($result->reviews);
        $this->assertCount(0, $result->reviews);
    }

    public function testThrowsModelNotFoundException(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model not found: non_existing_type");

        $mapper->map("non_existing_type", $product);
    }

    public function testThrowsAttributeNotFoundExceptionForInvalidGetter(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [
                    new Attribute(
                        "id",
                        "string",
                        getter: "getNonExistentMethod",
                    ),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );

        $this->expectException(AttributeNotFoundException::class);
        $this->expectExceptionMessage(
            "No method/property 'getNonExistentMethod'",
        );

        $mapper->map("product", $product);
    }

    public function testThrowsAttributeNotFoundExceptionForRelationWithoutGetter(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    // No getter
                    //
                    // Нет getter
                    new Relation("category", "oneToOne", "category"),
                ],
            ),
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [new Attribute("id", "string", getter: "getId")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );

        $this->expectException(AttributeNotFoundException::class);
        $this->expectExceptionMessage("No method/property 'category'");

        // Try to include a relation without a getter
        //
        // Пытаемся включить связь без getter
        $mapper->map("product", $product, ["category"]);
    }

    public function testThrowsModelNotFoundExceptionForMissingTargetModel(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "non_existing_model",
                        getter: "getCategory",
                    ),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $category = new CategoryForRead("10", "Test");
        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );
        $product->setCategory($category);

        $this->expectException(ModelNotFoundException::class);
        $this->expectExceptionMessage("Model not found: non_existing_model");

        $mapper->map("product", $product, ["category"]);
    }

    public function testMapWithMultipleDotNotationPaths(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "category",
                        getter: "getCategory",
                    ),
                    new Relation(
                        "reviews",
                        "oneToMany",
                        "review",
                        getter: "getReviews",
                    ),
                ],
            ),
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
                relations: [
                    new Relation(
                        "parent",
                        "manyToOne",
                        "category",
                        getter: "getParent",
                    ),
                ],
            ),
            new Model(
                class: ReviewForRead::class,
                type: "review",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "author",
                        "manyToOne",
                        "author",
                        getter: "getAuthor",
                    ),
                ],
            ),
            new Model(
                class: AuthorForRead::class,
                type: "author",
                attributes: [
                    new Attribute("id", "string", getter: "getId"),
                    new Attribute("name", "string", getter: "getName"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $parentCategory = new CategoryForRead("1", "Electronics");
        $category = new CategoryForRead("2", "Laptops");
        $category->setParent($parentCategory);

        $author = new AuthorForRead("50", "John");
        $review = new ReviewForRead("100", 5, "Great!");
        $review->setAuthor($author);

        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );
        $product->setCategory($category);
        $product->addReview($review);

        /**
         * @var object{
         *     category: object{
         *         id: string,
         *         name: string,
         *         parent: object{
         *             id: string,
         *             name: string
         *         }
         *     },
         *     reviews: list<object{
         *         id: string,
         *         author: object{
         *             id: string,
         *             name: string
         *         }
         *     }>
         * } $result
         */
        $result = $mapper->map("product", $product, [
            "category",
            "category.parent",
            "reviews",
            "reviews.author",
        ]);

        // Check category and its parent
        //
        // Проверяем category и его parent
        $this->assertSame("2", $result->category->id);
        $this->assertSame("Laptops", $result->category->name);
        $this->assertSame("1", $result->category->parent->id);
        $this->assertSame("Electronics", $result->category->parent->name);

        // Check reviews and their author
        //
        // Проверяем reviews и их author
        $this->assertCount(1, $result->reviews);
        $this->assertSame("100", $result->reviews[0]->id);
        $this->assertSame("50", $result->reviews[0]->author->id);
        $this->assertSame("John", $result->reviews[0]->author->name);
    }

    public function testMapWithNullInclude(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "category",
                        getter: "getCategory",
                    ),
                ],
            ),
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [new Attribute("id", "string", getter: "getId")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $category = new CategoryForRead("10", "Test");
        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );
        $product->setCategory($category);

        // With a null include, relations must not be included
        //
        // С null include связи не должны включаться
        $result = $mapper->map("product", $product);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectNotHasProperty("category", $result);
    }

    public function testMapWithEmptyInclude(): void
    {
        $config = new Config([
            new Model(
                class: ProductForRead::class,
                type: "product",
                attributes: [new Attribute("id", "string", getter: "getId")],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "category",
                        getter: "getCategory",
                    ),
                ],
            ),
            new Model(
                class: CategoryForRead::class,
                type: "category",
                attributes: [new Attribute("id", "string", getter: "getId")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $category = new CategoryForRead("10", "Test");
        $product = new ProductForRead(
            "1",
            "Test",
            1,
            1.0,
            true,
            new DateTimeImmutable(),
        );
        $product->setCategory($category);

        // With an empty include, relations must not be included
        //
        // С пустым include связи не должны включаться
        $result = $mapper->map("product", $product, []);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectNotHasProperty("category", $result);
    }
}
