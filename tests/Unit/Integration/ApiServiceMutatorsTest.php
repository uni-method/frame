<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    Mutators,
    PhaseType,
};
use UniMethod\Frame\Integration\{ApiService, ConstraintCheckerInterface, Error};
use UniMethod\Frame\Json\{ConvertToJson, ConvertToStdClass};
use UniMethod\Frame\Mapping\{
    AttributeAccessor,
    MappingFromObject,
    MappingToListAction,
    MappingToObject,
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Persistence\{
    EntitySaverInterface,
    ListLocators,
    ListRemovers,
    ListSavers,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Tests\Unit\Integration\Stub\MutatorAuthor;
use UniMethod\Frame\Tests\Unit\Stub\{RecordingLocator, SuffixMutator, User};
use UniMethod\Frame\Type\{Caster, TypeSpecParser};
use UniMethod\Frame\Validator\ActionValidator;

/**
 * Checks that ApiService calls MutatorRegistry::applyPhase()
 * at the right moments of the lifecycle (AfterValidate, beforeOutput).
 *
 * Проверяет, что ApiService вызывает MutatorRegistry::applyPhase()
 * в нужные моменты жизненного цикла (AfterValidate, beforeOutput).
 */
final class ApiServiceMutatorsTest extends TestCase
{
    private ?ApiService $service = null;

    /** @var \Closure(): ApiService */
    private \Closure $serviceFactory;

    private ConstraintCheckerInterface&Stub $constraintChecker;

    private EntitySaverInterface&Stub $saver;

    private MappingToObject&Stub $mappingToObject;

    private MappingFromObject&Stub $mappingFromObject;

    private MutatorRegistry&Stub $mutators;

    private Config $config;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Integration\Stub\MutatorAuthor",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Integration\Stub;
                class MutatorAuthor {
                    public function __construct(
                        public ?string $id = null,
                        public ?string $name = null,
                        public ?string $email = null,
                    ) {}
                }
            ');
        }
    }

    private function service(): ApiService
    {
        return $this->service ??= ($this->serviceFactory)();
    }

    protected function setUp(): void
    {
        $this->constraintChecker = $this->createStub(ConstraintCheckerInterface::class);
        $this->saver = $this->createStub(EntitySaverInterface::class);
        $this->mappingToObject = $this->createStub(MappingToObject::class);
        $this->mappingFromObject = $this->createStub(MappingFromObject::class);
        $this->mutators = $this->createStub(MutatorRegistry::class);

        $this->config = new Config(
            models: [
                new Model(
                    class: MutatorAuthor::class,
                    type: "author",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(name: "name", type: "string"),
                        new Attribute(name: "email", type: "string"),
                    ],
                ),
            ],
            actions: [
                new Action(
                    type: ActionType::Create,
                    model: "author",
                    entitySaver: "default",
                ),
            ],
            savers: ["default" => EntitySaverInterface::class],
        );

        $this->serviceFactory = fn (): ApiService => new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $this->config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $this->mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: $this->createStub(ListLocators::class),
            savers: new ListSavers(["default" => $this->saver]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $this->mutators,
        );
    }

    public function testMutatorsAreAppliedInCorrectPhases(): void
    {
        $mutators = $this->createMock(MutatorRegistry::class);
        $this->mutators = $mutators;
        $mappingFromObject = $this->createMock(MappingFromObject::class);
        $this->mappingFromObject = $mappingFromObject;
        $mappingToObject = $this->createMock(MappingToObject::class);
        $this->mappingToObject = $mappingToObject;
        $saver = $this->createMock(EntitySaverInterface::class);
        $this->saver = $saver;
        $constraintChecker = $this->createMock(ConstraintCheckerInterface::class);
        $this->constraintChecker = $constraintChecker;

        // Arrange
        //
        // Подготовка
        $rawContent = json_encode(
            [
                "name" => "John",
                "email" => "john@example.com",
            ],
            JSON_THROW_ON_ERROR,
        );
        $entity = new MutatorAuthor(
            id: "1",
            name: "John",
            email: "john@example.com",
        );

        $mappingToObject
            ->expects($this->once())
            ->method("map")
            ->willReturn($entity);

        $constraintChecker
            ->expects($this->once())
            ->method("check")
            ->willReturn([]);

        $saver
            ->expects($this->once())
            ->method("save")
            ->with($entity)
            ->willReturn($entity);

        //  Check applyPhase() calls with the correct sequence.
        //  BeforeOutput is applied by MappingFromObject::map, not ApiService
        //
        //  Проверяем вызовы applyPhase() с правильной последовательностью.
        //  BeforeOutput применяет MappingFromObject::map, не ApiService
        $expectedPhases = [
            PhaseType::AfterInput,
            PhaseType::AfterValidate,
        ];
        $callIndex = 0;

        $mutators
            ->expects($this->exactly(2))
            ->method("applyPhase")
            ->willReturnCallback(function (
                $phase,
                $e,
                $m,
                $a
            ) use (
                &$callIndex,
                $expectedPhases,
                $entity,
            ): void {
                $expected = $expectedPhases[$callIndex++] ?? null;
                TestCase::assertSame(
                    $expected,
                    $phase,
                    sprintf(
                        "Unexpected phase order: expected %s at call %d",
                        $expected->name,
                        $callIndex,
                    ),
                );
                TestCase::assertSame($entity, $e);
                TestCase::assertInstanceOf(Model::class, $m);
                TestCase::assertEquals(ActionType::Create, $a);
            });

        $mappingFromObject
            ->expects($this->once())
            ->method("map")
            ->willReturn((object) ["id" => "1", "name" => "John"]);

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(201, $result->statusCode);
        $this->assertTrue($result->encoded);
    }

    public function testMutatorsAreNotCalledWhenValidationFails(): void
    {
        $mutators = $this->createMock(MutatorRegistry::class);
        $this->mutators = $mutators;

        // Arrange
        //
        // Подготовка
        $rawContent = json_encode(["name" => "", "email" => ""], JSON_THROW_ON_ERROR);
        $entity = new MutatorAuthor(id: null, name: "", email: "");

        $this->mappingToObject->method("map")->willReturn($entity);

        $this->constraintChecker
            ->method("check")
            ->willReturn([new Error(422, "Validation failed")]);

        $mutators->expects($this->once())->method("applyPhase");

        // Act
        //
        // Действие
        $result = $this->service()->execute(
            modelType: "author",
            actionType: ActionType::Create,
            rawContent: $rawContent,
        );

        // Assert
        //
        // Проверка
        $this->assertEquals(422, $result->statusCode);
        $this->assertFalse($result->encoded);
    }

    /**
     * A BeforeOutput mutator is applied exactly once per request: the response
     * is built by MappingFromObject, ApiService does not run the phase again.
     *
     * BeforeOutput-мутатор применяется ровно один раз за запрос: ответ
     * собирает MappingFromObject, ApiService фазу повторно не запускает.
     */
    public function testBeforeOutputMutatorIsAppliedOnce(): void
    {
        $mutator = new SuffixMutator();

        $config = new Config(
            models: [
                new Model(
                    class: User::class,
                    type: "user",
                    attributes: [
                        new Attribute(name: "id", type: "string"),
                        new Attribute(
                            name: "name",
                            type: "string",
                            mutators: new Mutators(beforeOutput: ["suffix"]),
                        ),
                    ],
                ),
            ],
            actions: [
                new Action(type: ActionType::Read, model: "user", entityLocator: "loc"),
            ],
            locators: ["loc" => RecordingLocator::class],
        );

        $registry = new MutatorRegistry(["suffix" => $mutator], new AttributeAccessor());
        $registry->setConfig($config);

        $mappingFromObject = new MappingFromObject($registry, new TypeSpecParser(), new Caster());
        $mappingFromObject->setConfig($config);

        $this->constraintChecker->method("check")->willReturn([]);

        $service = new ApiService(
            constraintChecker: $this->constraintChecker,
            config: $config,
            treeBuilder: new TreeBuilder(),
            convertToStdClass: new ConvertToStdClass(),
            convertToJson: new ConvertToJson(),
            mappingFromObject: $mappingFromObject,
            mappingToObject: $this->mappingToObject,
            mappingToListAction: $this->createStub(MappingToListAction::class),
            actionValidator: $this->createStub(ActionValidator::class),
            locators: new ListLocators(["loc" => new RecordingLocator(new User(id: "1", name: "Alice"))]),
            savers: new ListSavers([]),
            removers: $this->createStub(ListRemovers::class),
            manipulators: $registry,
        );

        $result = $service->execute(modelType: "user", actionType: ActionType::Read, id: "1");

        $this->assertSame(200, $result->statusCode);
        $this->assertIsString($result->data);
        $this->assertSame(1, $mutator->calls);
        $this->assertSame("Alice!", json_decode($result->data)->name);
    }
}
