<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\Exception;
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
use UniMethod\Frame\Mapping\{AttributeAccessor, MappingToObject};
use UniMethod\Frame\Persistence\EntityLocatorContainerInterface;
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    Address,
    Author,
    DocumentWithRelations,
    EntityWithHiddenSetters,
    PersonWithMixedAccess,
    PersonWithPublicProperties,
    Product,
    Section,
    ShoppingCart,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

final class MappingToObjectGetterSetterTest extends TestCase
{
    private MappingToObject $mapper;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists("UniMethod\Frame\Tests\Unit\Mapping\Stub\PersonWithPublicProperties")) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class PersonWithPublicProperties {
                    public string $id;
                    public string $name;
                    public int $age;
                    public string $email;
                    private string $password = "";
                    public function setPassword(string $password): void { $this->password = $password; }
                    public function getPassword(): string { return $this->password; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getAge(): int { return $this->age; }
                    public function getEmail(): string { return $this->email; }
                }

                class PersonWithMixedAccess {
                    public string $id;
                    private string $name;
                    private int $age;
                    public function setName(string $name): void { $this->name = $name; }
                    public function setAge(int $age): void { $this->age = $age; }
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                    public function getAge(): int { return $this->age; }
                }

                class EntityWithHiddenSetters {
                    public string $id;
                    public string $visibleData;
                    public string $secretData;
                    public string $tempCache;
                    public function getId(): string { return $this->id; }
                    public function getVisibleData(): string { return $this->visibleData; }
                    public function getSecretData(): string { return $this->secretData; }
                    public function getTempCache(): string { return $this->tempCache; }
                }

                class ShoppingCart {
                    public string $id;
                    public ?Address $address = null;
                    /** @var Product[] */
                    public array $items = [];
                    public function getId(): string { return $this->id; }
                    public function getAddress(): ?Address { return $this->address; }
                    public function getItems(): array { return $this->items; }
                }

                class Address {
                    public string $street;
                    public string $city;
                    public function getStreet(): string { return $this->street; }
                    public function getCity(): string { return $this->city; }
                }

                class Product {
                    public string $sku;
                    public string $name;
                    public function getSku(): string { return $this->sku; }
                    public function getName(): string { return $this->name; }
                }

                class DocumentWithRelations {
                    public string $id;
                    private ?Author $author = null;
                    /** @var Section[] */
                    private array $sections = [];
                    public string $title;
                    public function setAuthor(?Author $author): void { $this->author = $author; }
                    public function setSections(array $sections): void { $this->sections = $sections; }
                    public function getId(): string { return $this->id; }
                    public function getTitle(): string { return $this->title; }
                    public function getAuthor(): ?Author { return $this->author; }
                    public function getSections(): array { return $this->sections; }
                }

                class Author {
                    public string $id;
                    public string $name;
                    public function getId(): string { return $this->id; }
                    public function getName(): string { return $this->name; }
                }

                class Section {
                    public string $id;
                    public string $content;
                    public function getId(): string { return $this->id; }
                    public function getContent(): string { return $this->content; }
                }
            ');
        }
    }

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->mapper = new MappingToObject(
            new TreeBuilder(),
            new TypeSpecParser(),
            new Caster(),
            $this->createStub(EntityLocatorContainerInterface::class),
            new AttributeAccessor(),
        );
    }

    public function testAutoDetectPublicPropertySetter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: PersonWithPublicProperties::class,
                    type: "person",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("name", "string"),
                        new Attribute("age", "int"),
                        new Attribute("email", "string"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "person")],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->name = "John Doe";
        $source->age = 30;
        $source->email = "john@example.com";

        /** @var PersonWithPublicProperties $result */
        $result = $this->mapper->map("person", $source, ActionType::Create);

        $this->assertSame("1", $result->getId());
        $this->assertSame("John Doe", $result->getName());
        $this->assertSame(30, $result->getAge());
        $this->assertSame("john@example.com", $result->getEmail());
    }

    public function testExplicitSetterFalseIgnoresAttribute(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: PersonWithPublicProperties::class,
                    type: "person",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("name", "string", setter: false),
                        new Attribute("age", "int"),
                        new Attribute("email", "string", setter: false),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "person")],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->name = "John Doe";
        $source->age = 30;
        $source->email = "john@example.com";

        /** @var PersonWithPublicProperties $result */
        $result = $this->mapper->map("person", $source, ActionType::Create);

        $this->assertSame("1", $result->getId());
        $this->assertSame(30, $result->getAge());
    }

    public function testMixedExplicitAndAutoDetectSetters(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: PersonWithMixedAccess::class,
                    type: "person",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("name", "string", setter: "setName"),
                        new Attribute("age", "int", setter: "setAge"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "person")],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->name = "John Doe";
        $source->age = 30;

        /** @var PersonWithMixedAccess $result */
        $result = $this->mapper->map("person", $source, ActionType::Create);

        $this->assertSame("1", $result->getId());
        $this->assertSame("John Doe", $result->getName());
        $this->assertSame(30, $result->getAge());
    }

    public function testAutoDetectIgnoresPrivateProperties(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: PersonWithMixedAccess::class,
                    type: "person",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("name", "string"),
                        new Attribute("age", "int"),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "person")],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->name = "John Doe";
        $source->age = 30;

        /** @var PersonWithMixedAccess $result */
        $result = $this->mapper->map("person", $source, ActionType::Create);

        $this->assertSame("1", $result->getId());
    }

    public function testExplicitSetterFalseOverridesPublicProperty(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: EntityWithHiddenSetters::class,
                    type: "entity",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("visibleData", "string"),
                        new Attribute("secretData", "string", setter: false),
                        new Attribute("tempCache", "string", setter: false),
                    ],
                ),
            ],
            actions: [new Action(ActionType::Create, "entity")],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->visibleData = "visible";
        $source->secretData = "secret";
        $source->tempCache = "cache";

        /** @var EntityWithHiddenSetters $result */
        $result = $this->mapper->map("entity", $source, ActionType::Create);

        $this->assertSame("1", $result->getId());
        $this->assertSame("visible", $result->getVisibleData());
    }

    public function testAutoDetectPublicRelationSetter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: ShoppingCart::class,
                    type: "cart",
                    attributes: [new Attribute("id", "string")],
                    relations: [new Relation("address", "oneToOne", "address")],
                ),
                new Model(
                    class: Address::class,
                    type: "address",
                    attributes: [
                        new Attribute("street", "string"),
                        new Attribute("city", "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "cart",
                    writable: [
                        "address" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->address = new stdClass();
        $source->address->street = "123 Main St";
        $source->address->city = "Springfield";

        /** @var ShoppingCart $result */
        $result = $this->mapper->map(
            "cart",
            $source,
            ActionType::Create,
            "address",
        );

        $this->assertSame("1", $result->getId());
        $this->assertInstanceOf(Address::class, $result->getAddress());
        $this->assertSame("123 Main St", $result->getAddress()->getStreet());
        $this->assertSame("Springfield", $result->getAddress()->getCity());
    }

    public function testAutoDetectPublicArrayRelationSetter(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: ShoppingCart::class,
                    type: "cart",
                    attributes: [new Attribute("id", "string")],
                    relations: [new Relation("items", "oneToMany", "product")],
                ),
                new Model(
                    class: Product::class,
                    type: "product",
                    attributes: [
                        new Attribute("sku", "string"),
                        new Attribute("name", "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "cart",
                    writable: [
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->items = [
            (object) ["sku" => "SKU1", "name" => "Product 1"],
            (object) ["sku" => "SKU2", "name" => "Product 2"],
        ];

        /** @var ShoppingCart $result */
        $result = $this->mapper->map(
            "cart",
            $source,
            ActionType::Create,
            "items",
        );

        $this->assertSame("1", $result->getId());
        $this->assertCount(2, $result->getItems());
        $this->assertSame("SKU1", $result->getItems()[0]->getSku());
        $this->assertSame("Product 1", $result->getItems()[0]->getName());
        $this->assertSame("SKU2", $result->getItems()[1]->getSku());
    }

    public function testRelationSetterFalseIgnoresRelation(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: ShoppingCart::class,
                    type: "cart",
                    attributes: [new Attribute("id", "string")],
                    relations: [
                        new Relation(
                            "address",
                            "oneToOne",
                            "address",
                            setter: false,
                        ),
                        new Relation("items", "oneToMany", "product"),
                    ],
                ),
                new Model(
                    class: Address::class,
                    type: "address",
                    attributes: [new Attribute("street", "string")],
                ),
                new Model(
                    class: Product::class,
                    type: "product",
                    attributes: [new Attribute("sku", "string")],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "cart",
                    writable: [
                        "items" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->address = (object) ["street" => "123 Main St"];
        $source->items = [(object) ["sku" => "SKU1"]];

        /** @var ShoppingCart $result */
        $result = $this->mapper->map(
            "cart",
            $source,
            ActionType::Create,
            "items",
        );

        $this->assertSame("1", $result->getId());
        $this->assertNull($result->getAddress());
        $this->assertCount(1, $result->getItems());
    }

    public function testMixedExplicitAndAutoDetectRelationSetters(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: DocumentWithRelations::class,
                    type: "document",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("title", "string"),
                    ],
                    relations: [
                        new Relation(
                            "author",
                            "oneToOne",
                            "author",
                            setter: "setAuthor",
                        ),
                        new Relation(
                            "sections",
                            "oneToMany",
                            "section",
                            setter: "setSections",
                        ),
                    ],
                ),
                new Model(
                    class: Author::class,
                    type: "author",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("name", "string"),
                    ],
                ),
                new Model(
                    class: Section::class,
                    type: "section",
                    attributes: [
                        new Attribute("id", "string"),
                        new Attribute("content", "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    ActionType::Create,
                    "document",
                    writable: [
                        "author" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                        "sections" => new WritableConfig(
                            mode: RelationWriteMode::Create,
                        ),
                    ],
                ),
            ],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->title = "My Document";
        $source->author = (object) ["id" => "100", "name" => "John Doe"];
        $source->sections = [
            (object) ["id" => "200", "content" => "Section 1"],
            (object) ["id" => "201", "content" => "Section 2"],
        ];

        /** @var DocumentWithRelations $result */
        $result = $this->mapper->map(
            "document",
            $source,
            ActionType::Create,
            "author,sections",
        );

        $this->assertSame("1", $result->getId());
        $this->assertSame("My Document", $result->getTitle());
        $this->assertInstanceOf(Author::class, $result->getAuthor());
        $this->assertSame("100", $result->getAuthor()->getId());
        $this->assertCount(2, $result->getSections());
        $this->assertSame("Section 1", $result->getSections()[0]->getContent());
    }

    public function testAutoDetectWithNullValues(): void
    {
        $config = new Config(
            models: [
                new Model(
                    class: ShoppingCart::class,
                    type: "cart",
                    attributes: [new Attribute("id", "string")],
                    relations: [new Relation("address", "oneToOne", "address")],
                ),
                new Model(
                    class: Address::class,
                    type: "address",
                    attributes: [new Attribute("street", "string")],
                ),
            ],
            actions: [new Action(ActionType::Create, "cart")],
        );

        $this->mapper->setConfig($config);

        $source = new stdClass();
        $source->id = "1";
        $source->address = null;

        /** @var ShoppingCart $result */
        $result = $this->mapper->map(
            "cart",
            $source,
            ActionType::Create,
            "address",
        );

        $this->assertSame("1", $result->getId());
        $this->assertNull($result->getAddress());
    }
}
