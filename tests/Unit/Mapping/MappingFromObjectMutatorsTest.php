<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    ActionType,
    Attribute,
    Config,
    Model,
    PhaseType,
    Relation,
};
use UniMethod\Frame\Mapping\MappingFromObject;
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Tests\Unit\Mapping\Stub\{
    TestMappingFromObjectCard,
    TestMappingFromObjectCardType,
    TestMappingFromObjectNotification,
    TestMappingFromObjectUser,
};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

/**
 * Checks that MappingFromObject calls mutators for nested relations
 * of any depth (for example, cards.cardType)
 *
 * Проверяет, что MappingFromObject вызывает мутаторы для вложенных связей
 * любого уровня глубины (например, cards.cardType)
 */
final class MappingFromObjectMutatorsTest extends TestCase
{
    private MappingFromObject $mapper;

    private MockObject $mutators;

    private Config $config;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestMappingFromObjectUser",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestMappingFromObjectUser
                {
                    public function __construct(
                        public string $id,
                        public string $name,
                        public array $notifications = [],
                        public array $cards = [],
                    ) {
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestMappingFromObjectNotification",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestMappingFromObjectNotification
                {
                    public function __construct(public string $id, public string $message)
                    {
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestMappingFromObjectCardType",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestMappingFromObjectCardType
                {
                    public function __construct(public string $id, public string $typeName)
                    {
                    }
                }
            ');
        }

        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Mapping\Stub\TestMappingFromObjectCard",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Mapping\Stub;

                class TestMappingFromObjectCard
                {
                    public function __construct(
                        public string $id,
                        public string $number,
                        public ?TestMappingFromObjectCardType $cardType = null,
                    ) {
                    }
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->mutators = $this->createMock(MutatorRegistry::class);

        $this->config = new Config(
            models: [
                new Model(
                    class: TestMappingFromObjectUser::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                    ],
                    relations: [
                        new Relation(
                            name: "notifications",
                            type: Relation::ONE_TO_MANY,
                            targetModel: "notification",
                        ),
                        new Relation(
                            name: "cards",
                            type: Relation::ONE_TO_MANY,
                            targetModel: "card",
                        ),
                    ],
                ),
                new Model(
                    class: TestMappingFromObjectNotification::class,
                    type: "notification",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "message", type: "string"),
                    ],
                ),
                new Model(
                    class: TestMappingFromObjectCard::class,
                    type: "card",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "number", type: "string"),
                    ],
                    relations: [
                        new Relation(
                            name: "cardType",
                            type: Relation::MANY_TO_ONE,
                            targetModel: "cardType",
                        ),
                    ],
                ),
                new Model(
                    class: TestMappingFromObjectCardType::class,
                    type: "cardType",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "typeName", type: "string"),
                    ],
                ),
            ],
            actions: [],
        );

        $this->mapper = new MappingFromObject(
            $this->mutators,
            new TypeSpecParser(),
            new Caster(),
        );
        $this->mapper->setConfig($this->config);
    }

    public function testMutatorsAreAppliedToNestedRelationsAtAllLevels(): void
    {
        // Arrange
        //
        // Подготовка
        $cardType1 = new TestMappingFromObjectCardType(
            id: "ct1",
            typeName: "Visa",
        );
        $cardType2 = new TestMappingFromObjectCardType(
            id: "ct2",
            typeName: "Mastercard",
        );
        $card1 = new TestMappingFromObjectCard(
            id: "c1",
            number: "1234",
            cardType: $cardType1,
        );
        $card2 = new TestMappingFromObjectCard(
            id: "c2",
            number: "5678",
            cardType: $cardType2,
        );
        $notification1 = new TestMappingFromObjectNotification(
            id: "n1",
            message: "Hello",
        );
        $notification2 = new TestMappingFromObjectNotification(
            id: "n2",
            message: "World",
        );

        $user = new TestMappingFromObjectUser(
            id: "u1",
            name: "John",
            notifications: [$notification1, $notification2],
            cards: [$card1, $card2],
        );

        // Expect mutator calls for all levels:
        // The order of calls matches the order of traversal in the recursion:
        // 1. user (top level)
        // 2. notification1 (the first element of the notifications array)
        // 3. notification2 (the second element of the notifications array)
        // 4. card1 (the first element of the cards array)
        // 5. cardType1 (the card1.cardType relation is processed immediately)
        // 6. card2 (the second element of the cards array)
        // 7. cardType2 (the card2.cardType relation is processed immediately)
        //
        // Ожидаем вызовы мутаторов для всех уровней:
        // Порядок вызовов соответствует порядку обхода в рекурсии:
        // 1. user (верхний уровень)
        // 2. notification1 (первый элемент массива notifications)
        // 3. notification2 (второй элемент массива notifications)
        // 4. card1 (первый элемент массива cards)
        // 5. cardType1 (связь card1.cardType обрабатывается сразу)
        // 6. card2 (второй элемент массива cards)
        // 7. cardType2 (связь card2.cardType обрабатывается сразу)

        $expectedCalls = [
            ["user", $user],
            ["notification", $notification1],
            ["notification", $notification2],
            ["card", $card1],
            // right after card1
            //
            // сразу после card1
            ["cardType", $cardType1],
            ["card", $card2],
            // right after card2
            //
            // сразу после card2
            ["cardType", $cardType2],
        ];

        $callIndex = 0;

        $this->mutators
            ->expects($this->exactly(7))
            ->method("applyPhase")
            ->willReturnCallback(function (
                $phase,
                $entity,
                $model,
                $actionType,
            ) use (
                &$callIndex,
                $expectedCalls
            ): void {
                TestCase::assertSame(PhaseType::BeforeOutput, $phase);
                TestCase::assertSame(ActionType::Read, $actionType);

                [$expectedType, $expectedEntity] = $expectedCalls[$callIndex++];
                TestCase::assertSame(
                    $expectedEntity,
                    $entity,
                    "Expected entity at call " . $callIndex,
                );
                TestCase::assertSame(
                    $expectedType,
                    $model->type,
                    "Expected model type at call " . $callIndex,
                );
            });

        // Act
        //
        // Действие
        $result = $this->mapper->map(
            "user",
            $user,
            ["notifications", "cards.cardType"],
            ActionType::Read,
        );

        // Assert
        //
        // Проверка
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals("u1", $result->id);
        $this->assertEquals("John", $result->name);
        $this->assertIsArray($result->notifications);
        $this->assertCount(2, $result->notifications);
        $this->assertIsArray($result->cards);
        $this->assertCount(2, $result->cards);
        $this->assertInstanceOf(\stdClass::class, $result->cards[0]->cardType);
        $this->assertEquals("Visa", $result->cards[0]->cardType->typeName);
        $this->assertInstanceOf(\stdClass::class, $result->cards[1]->cardType);
        $this->assertEquals(
            "Mastercard",
            $result->cards[1]->cardType->typeName,
        );
    }

    public function testMutatorsAreAppliedToSingleNestedRelation(): void
    {
        // Arrange
        //
        // Подготовка
        $cardType = new TestMappingFromObjectCardType(
            id: "ct1",
            typeName: "Mastercard",
        );
        $card = new TestMappingFromObjectCard(
            id: "c1",
            number: "9999",
            cardType: $cardType,
        );

        $callIndex = 0;
        $expectedCalls = [["card", $card], ["cardType", $cardType]];

        $this->mutators
            ->expects($this->exactly(2))
            ->method("applyPhase")
            ->willReturnCallback(function (
                $phase,
                $entity,
                $model,
                $actionType,
            ) use (
                &$callIndex,
                $expectedCalls
            ): void {
                TestCase::assertSame(PhaseType::BeforeOutput, $phase);
                TestCase::assertSame(ActionType::List, $actionType);

                [$expectedType, $expectedEntity] = $expectedCalls[$callIndex++];
                TestCase::assertSame($expectedEntity, $entity);
                TestCase::assertSame($expectedType, $model->type);
            });

        // Act
        //
        // Действие
        $result = $this->mapper->map(
            "card",
            $card,
            ["cardType"],
            ActionType::List,
        );

        // Assert
        //
        // Проверка
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals("c1", $result->id);
        $this->assertInstanceOf(\stdClass::class, $result->cardType);
        $this->assertEquals("Mastercard", $result->cardType->typeName);
    }

    public function testMutatorsNotCalledWhenActionTypeIsNull(): void
    {
        // Arrange
        //
        // Подготовка
        $notification = new TestMappingFromObjectNotification(
            id: "n1",
            message: "Test",
        );

        $this->mutators->expects($this->never())->method("applyPhase");

        // Act
        //
        // Действие
        $result = $this->mapper->map(
            "notification",
            $notification, // actionType = null
        );

        // Assert
        //
        // Проверка
        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals("n1", $result->id);
    }
}
