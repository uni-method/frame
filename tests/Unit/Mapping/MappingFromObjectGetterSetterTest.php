<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{Attribute, Config, Model, Relation};
use UniMethod\Frame\Mapping\MappingFromObject;
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    CategoryDto,
    CustomerDto,
    EntityWithHiddenFields,
    ItemDto,
    OrderWithRelations,
    ProductWithPublicRelations,
    TagDto,
    UserWithMixedAccess,
    UserWithPublicProperties,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingFromObjectGetterSetterTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Test classes for checking getter auto-detection
        //
        // Тестовые классы для проверки автоопределения getter

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\UserWithPublicProperties",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class UserWithPublicProperties
                {
                    public string $id;

                    public string $email;

                    public string $name;

                    private string $password;

                    public function __construct(
                        string $id,
                        string $email,
                        string $name,
                        string $password,
                    ) {
                        $this->id = $id;
                        $this->email = $email;
                        $this->name = $name;
                        $this->password = $password;
                    }

                    public function getPassword(): string
                    {
                        return $this->password;
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\UserWithMixedAccess",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class UserWithMixedAccess
                {
                    public string $id;

                    private string $email;

                    private string $name;

                    public function __construct(string $id, string $email, string $name)
                    {
                        $this->id = $id;
                        $this->email = $email;
                        $this->name = $name;
                    }

                    public function getEmail(): string
                    {
                        return $this->email;
                    }

                    public function getName(): string
                    {
                        return $this->name;
                    }
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\CategoryDto")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class CategoryDto
                {
                    public string $id;

                    public string $name;

                    public function __construct(string $id, string $name)
                    {
                        $this->id = $id;
                        $this->name = $name;
                    }
                }
            ');
        }

        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\TagDto")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TagDto
                {
                    public string $id;

                    public string $name;

                    public function __construct(string $id, string $name)
                    {
                        $this->id = $id;
                        $this->name = $name;
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\ProductWithPublicRelations",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class ProductWithPublicRelations
                {
                    public string $id;

                    public ?CategoryDto $category = null;

                    /** @var TagDto[] */
                    public array $tags = [];

                    public function __construct(string $id)
                    {
                        $this->id = $id;
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\EntityWithHiddenFields",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class EntityWithHiddenFields
                {
                    public string $id;

                    public string $publicData = "public";

                    public string $secretData = "secret";

                    public string $internalCache = "cache";

                    public function __construct(string $id)
                    {
                        $this->id = $id;
                    }
                }
            ');
        }

        if (
            !class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\CustomerDto")
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class CustomerDto
                {
                    public string $id;

                    public string $name;

                    public function __construct(string $id, string $name)
                    {
                        $this->id = $id;
                        $this->name = $name;
                    }
                }
            ');
        }

        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\ItemDto")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class ItemDto
                {
                    public string $id;

                    public string $name;

                    public function __construct(string $id, string $name)
                    {
                        $this->id = $id;
                        $this->name = $name;
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\OrderWithRelations",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class OrderWithRelations
                {
                    public string $id;

                    private ?CustomerDto $customer = null;

                    /** @var ItemDto[] */
                    private array $items = [];

                    public string $notes;

                    public function __construct(string $id, string $notes = "")
                    {
                        $this->id = $id;
                        $this->notes = $notes;
                    }

                    public function getCustomer(): ?CustomerDto
                    {
                        return $this->customer;
                    }

                    public function setCustomer(?CustomerDto $customer): void
                    {
                        $this->customer = $customer;
                    }

                    /**
                     * @return ItemDto[]
                     */
                    public function getItems(): array
                    {
                        return $this->items;
                    }

                    /**
                     * @param ItemDto[] $items
                     */
                    public function setItems(array $items): void
                    {
                        $this->items = $items;
                    }
                }
            ');
        }
    }

    public function testAutoDetectPublicPropertyGetter(): void
    {
        $config = new Config([
            new Model(
                class: UserWithPublicProperties::class,
                type: "user",
                attributes: [
                    // getter is not specified - auto-detection
                    //
                    // getter не указан - автоопределение
                    new Attribute("id", "string"),
                    // getter is not specified - auto-detection
                    //
                    // getter не указан - автоопределение
                    new Attribute("email", "string"),
                    // getter is not specified - auto-detection
                    //
                    // getter не указан - автоопределение
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $user = new UserWithPublicProperties(
            "1",
            "test@example.com",
            "John Doe",
            "secret",
        );

        /** @var object{id: string, email: string, name: string} $result */
        $result = $mapper->map("user", $user);

        $this->assertSame("1", $result->id);
        $this->assertSame("test@example.com", $result->email);
        $this->assertSame("John Doe", $result->name);
    }

    public function testExplicitGetterFalseIgnoresAttribute(): void
    {
        $config = new Config([
            new Model(
                class: UserWithPublicProperties::class,
                type: "user",
                attributes: [
                    // auto-detection
                    //
                    // автоопределение
                    new Attribute("id", "string"),
                    // explicit prohibition
                    //
                    // явный запрет
                    new Attribute("email", "string", getter: false),
                    // auto-detection
                    //
                    // автоопределение
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $user = new UserWithPublicProperties(
            "1",
            "test@example.com",
            "John Doe",
            "secret",
        );

        $result = $mapper->map("user", $user);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectHasProperty("name", $result);
        $this->assertObjectNotHasProperty("email", $result); // getter = false
    }

    public function testMixedExplicitAndAutoDetectGetters(): void
    {
        $config = new Config([
            new Model(
                class: UserWithMixedAccess::class,
                type: "user",
                attributes: [
                    // public property - auto-detection
                    //
                    // публичное свойство - автоопределение
                    new Attribute("id", "string"),
                    // explicit method
                    //
                    // явный метод
                    new Attribute("email", "string", getter: "getEmail"),
                    // explicit method
                    //
                    // явный метод
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

        $user = new UserWithMixedAccess("1", "test@example.com", "John Doe");

        /** @var object{id: string, email: string, name: string} $result */
        $result = $mapper->map("user", $user);

        $this->assertSame("1", $result->id);
        $this->assertSame("test@example.com", $result->email);
        $this->assertSame("John Doe", $result->name);
    }

    public function testAutoDetectIgnoresPrivateProperties(): void
    {
        $config = new Config([
            new Model(
                class: UserWithMixedAccess::class,
                type: "user",
                attributes: [
                    // public - OK
                    //
                    // публичное - OK
                    new Attribute("id", "string"),
                    // private - auto-detection will not work
                    //
                    // приватное - автоопределение не сработает
                    new Attribute("email", "string"),
                    // private - auto-detection will not work
                    //
                    // приватное - автоопределение не сработает
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $user = new UserWithMixedAccess("1", "test@example.com", "John Doe");

        $result = $mapper->map("user", $user);

        $this->assertObjectHasProperty("id", $result);
        // private property
        //
        // приватное свойство
        $this->assertObjectNotHasProperty("email", $result);
        // private property
        //
        // приватное свойство
        $this->assertObjectNotHasProperty("name", $result);
    }

    public function testExplicitGetterFalseOverridesPublicProperty(): void
    {
        $config = new Config([
            new Model(
                class: EntityWithHiddenFields::class,
                type: "entity",
                attributes: [
                    // auto-detection
                    //
                    // автоопределение
                    new Attribute("id", "string"),
                    // auto-detection
                    //
                    // автоопределение
                    new Attribute("publicData", "string"),
                    // explicit prohibition
                    //
                    // явный запрет
                    new Attribute("secretData", "string", getter: false),
                    // explicit prohibition
                    //
                    // явный запрет
                    new Attribute("internalCache", "string", getter: false),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $entity = new EntityWithHiddenFields("1");

        $result = $mapper->map("entity", $entity);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectHasProperty("publicData", $result);
        $this->assertObjectNotHasProperty("secretData", $result); // getter = false
        $this->assertObjectNotHasProperty("internalCache", $result); // getter = false
    }

    public function testAutoDetectPublicRelationGetter(): void
    {
        $config = new Config([
            new Model(
                class: ProductWithPublicRelations::class,
                type: "product",
                attributes: [new Attribute("id", "string")],
                relations: [
                    // getter is not specified - auto-detection
                    //
                    // getter не указан - автоопределение
                    new Relation("category", "oneToOne", "category"),
                ],
            ),
            new Model(
                class: CategoryDto::class,
                type: "category",
                attributes: [
                    new Attribute("id", "string"),
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductWithPublicRelations("1");
        $product->category = new CategoryDto("10", "Electronics");

        /** @var object{id: string, category: object{id: string, name: string}} $result */
        $result = $mapper->map("product", $product, ["category"]);

        $this->assertSame("1", $result->id);
        $this->assertInstanceOf(stdClass::class, $result->category);
        $this->assertSame("10", $result->category->id);
        $this->assertSame("Electronics", $result->category->name);
    }

    public function testAutoDetectPublicArrayRelationGetter(): void
    {
        $config = new Config([
            new Model(
                class: ProductWithPublicRelations::class,
                type: "product",
                attributes: [new Attribute("id", "string")],
                relations: [
                    // getter is not specified - auto-detection
                    //
                    // getter не указан - автоопределение
                    new Relation("tags", "oneToMany", "tag"),
                ],
            ),
            new Model(
                class: TagDto::class,
                type: "tag",
                attributes: [
                    new Attribute("id", "string"),
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductWithPublicRelations("1");
        $product->tags = [
            new TagDto("100", "electronics"),
            new TagDto("101", "laptop"),
        ];

        /** @var object{id: string, tags: list<object{id: string, name: string}>} $result */
        $result = $mapper->map("product", $product, ["tags"]);

        $this->assertSame("1", $result->id);
        $this->assertCount(2, $result->tags);
        $this->assertSame("100", $result->tags[0]->id);
        $this->assertSame("electronics", $result->tags[0]->name);
        $this->assertSame("101", $result->tags[1]->id);
        $this->assertSame("laptop", $result->tags[1]->name);
    }

    public function testRelationGetterFalseIgnoresRelation(): void
    {
        $config = new Config([
            new Model(
                class: ProductWithPublicRelations::class,
                type: "product",
                attributes: [new Attribute("id", "string")],
                relations: [
                    new Relation(
                        "category",
                        "oneToOne",
                        "category",
                        getter: false,
                        // explicit prohibition
                        //
                        // явный запрет
                    ),
                    // auto-detection
                    //
                    // автоопределение
                    new Relation("tags", "oneToMany", "tag"),
                ],
            ),
            new Model(
                class: CategoryDto::class,
                type: "category",
                attributes: [new Attribute("id", "string")],
            ),
            new Model(
                class: TagDto::class,
                type: "tag",
                attributes: [new Attribute("id", "string")],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $product = new ProductWithPublicRelations("1");
        $product->category = new CategoryDto("10", "Electronics");
        $product->tags = [new TagDto("100", "tag1")];

        /** @var object{id: string, tags?: list<object{id: string}>} $result */
        $result = $mapper->map("product", $product, ["category", "tags"]);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectNotHasProperty("category", $result); // getter = false
        // auto-detection worked
        //
        // автоопределение сработало
        $this->assertObjectHasProperty("tags", $result);
    }

    public function testMixedExplicitAndAutoDetectRelationGetters(): void
    {
        $config = new Config([
            new Model(
                class: OrderWithRelations::class,
                type: "order",
                attributes: [
                    // public property
                    //
                    // публичное свойство
                    new Attribute("id", "string"),
                    // public property
                    //
                    // публичное свойство
                    new Attribute("notes", "string"),
                ],
                relations: [
                    new Relation(
                        "customer",
                        "oneToOne",
                        "customer",
                        getter: "getCustomer",
                        // explicit method
                        //
                        // явный метод
                    ),
                    new Relation(
                        "items",
                        "oneToMany",
                        "item",
                        getter: "getItems",
                        // explicit method
                        //
                        // явный метод
                    ),
                ],
            ),
            new Model(
                class: CustomerDto::class,
                type: "customer",
                attributes: [
                    new Attribute("id", "string"),
                    new Attribute("name", "string"),
                ],
            ),
            new Model(
                class: ItemDto::class,
                type: "item",
                attributes: [
                    new Attribute("id", "string"),
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $order = new OrderWithRelations("1", "Test notes");
        $order->setCustomer(new CustomerDto("100", "John Doe"));
        $order->setItems([
            new ItemDto("200", "Item 1"),
            new ItemDto("201", "Item 2"),
        ]);

        /**
         * @var object{
         *     id: string,
         *     notes: string,
         *     customer: object{id: string, name: string},
         *     items: list<object{id: string, name: string}>
         * } $result
         */
        $result = $mapper->map("order", $order, ["customer", "items"]);

        $this->assertSame("1", $result->id);
        $this->assertSame("Test notes", $result->notes);
        $this->assertSame("100", $result->customer->id);
        $this->assertSame("John Doe", $result->customer->name);
        $this->assertCount(2, $result->items);
        $this->assertSame("200", $result->items[0]->id);
        $this->assertSame("Item 1", $result->items[0]->name);
    }

    public function testComplexConfigWithMixedGetters(): void
    {
        $config = new Config([
            new Model(
                class: UserWithPublicProperties::class,
                type: "user",
                attributes: [
                    // auto-detection of a public property
                    //
                    // автоопределение публичного свойства
                    new Attribute("id", "string"),
                    // explicit property specification
                    //
                    // явное указание свойства
                    new Attribute("email", "string", getter: "email"),
                    // explicit prohibition (although public)
                    //
                    // явный запрет (хотя публичное)
                    new Attribute("name", "string", getter: false),
                    // explicit method
                    //
                    // явный метод
                    new Attribute("password", "string", getter: "getPassword"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        $user = new UserWithPublicProperties(
            "1",
            "test@example.com",
            "John Doe",
            "secret123",
        );

        /** @var object{id: string, email: string, password: string} $result */
        $result = $mapper->map("user", $user);

        $this->assertObjectHasProperty("id", $result);
        $this->assertObjectHasProperty("email", $result);
        $this->assertObjectNotHasProperty("name", $result); // getter = false
        $this->assertObjectHasProperty("password", $result);
        $this->assertSame("secret123", $result->password);
    }

    public function testCachingPublicPropertiesAcrossMultipleMappings(): void
    {
        $config = new Config([
            new Model(
                class: UserWithPublicProperties::class,
                type: "user",
                attributes: [
                    new Attribute("id", "string"),
                    new Attribute("email", "string"),
                    new Attribute("name", "string"),
                ],
            ),
        ]);

        $mapper = new MappingFromObject(
            $this->createStub(MutatorRegistry::class),
            new TypeSpecParser(),
            new Caster(),
        );
        $mapper->setConfig($config);

        // Create several objects of the same class
        //
        // Создаем несколько объектов одного класса
        $user1 = new UserWithPublicProperties(
            "1",
            "user1@example.com",
            "User 1",
            "pass1",
        );
        $user2 = new UserWithPublicProperties(
            "2",
            "user2@example.com",
            "User 2",
            "pass2",
        );
        $user3 = new UserWithPublicProperties(
            "3",
            "user3@example.com",
            "User 3",
            "pass3",
        );

        // Map all objects - the cache must be used for the second and third
        //
        // Маппим все объекты - кеш должен использоваться для второго и третьего
        /** @var object{id: string, email: string, name: string} $result1 */
        $result1 = $mapper->map("user", $user1);
        /** @var object{id: string, email: string, name: string} $result2 */
        $result2 = $mapper->map("user", $user2);
        /** @var object{id: string, email: string, name: string} $result3 */
        $result3 = $mapper->map("user", $user3);

        // Check that all objects are processed correctly
        //
        // Проверяем, что все объекты корректно обработаны
        $this->assertSame("1", $result1->id);
        $this->assertSame("user1@example.com", $result1->email);

        $this->assertSame("2", $result2->id);
        $this->assertSame("user2@example.com", $result2->email);

        $this->assertSame("3", $result3->id);
        $this->assertSame("user3@example.com", $result3->email);
    }

    public function testNestedRelationsWithAutoDetectAndExplicitGetters(): void
    {
        $config = new Config([
            new Model(
                class: ProductWithPublicRelations::class,
                type: "product",
                attributes: [new Attribute("id", "string")],
                relations: [
                    // auto-detection
                    //
                    // автоопределение
                    new Relation("category", "oneToOne", "category"),
                ],
            ),
            new Model(
                class: CategoryDto::class,
                type: "category",
                attributes: [
                    // auto-detection
                    //
                    // автоопределение
                    new Attribute("id", "string"),
                    // explicit property specification
                    //
                    // явное указание свойства
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

        $product = new ProductWithPublicRelations("1");
        $product->category = new CategoryDto("10", "Electronics");

        /** @var object{id: string, category: object{id: string, name: string}} $result */
        $result = $mapper->map("product", $product, ["category"]);

        $this->assertSame("1", $result->id);
        $this->assertSame("10", $result->category->id);
        $this->assertSame("Electronics", $result->category->name);
    }
}
