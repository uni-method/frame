<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Action, ActionType, Attribute, Model, Relation};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
    PaginationRule,
};
use UniMethod\Frame\Validator\ConfigValidator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * Filtering rules by a whole relation: the path resolves to a relation, the value is
 * the id of the target model. Operators are limited to equal/in, the target model must
 * have a read action with a locator, the DTO property follows the general path rule.
 *
 * Правила фильтрации по связи целиком: путь резолвится в связь, значение —
 * id целевой модели. Операторы ограничены equal/in, у целевой модели обязано
 * быть read-действие с локатором, свойство DTO — по общему правилу путей.
 */
final class ConfigValidatorRelationFilterTest extends TestCase
{
    private const string STUB_NS = "UniMethod\Frame\Tests\Unit\Validator\Stub";

    private ConfigValidator $validator;

    public static function setUpBeforeClass(): void
    {
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Validator\Stub\VGardenQuery",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VGUser {}

                class VGarden {}

                class VGardenQuery {
                    public ?VGardenFilter $filter = null;
                }

                class VGardenFilter {
                    public ?VGardenEqual $equal = null;
                    public ?VGardenIn $in = null;
                }

                class VGardenEqual {
                    public ?VGUser $user = null;
                }

                class VGardenIn {
                    public ?array $user = null;
                }
            ');
        }

        // A set with a scalar property instead of a relation property
        //
        // Набор со скалярным свойством вместо свойства-связи
        if (
            !class_exists(
                "UniMethod\Frame\Tests\Unit\Validator\Stub\VScalarQuery",
            )
        ) {
            eval('
                namespace UniMethod\Frame\Tests\Unit\Validator\Stub;

                class VScalar {}

                class VScalarQuery {
                    public ?VScalarFilter $filter = null;
                }

                class VScalarFilter {
                    public ?VScalarEqual $equal = null;
                }

                class VScalarEqual {
                    public ?int $relUserRelId = null;
                }
            ');
        }
    }

    protected function setUp(): void
    {
        $this->validator = new ConfigValidator();
    }

    /**
     * Stub classes are created via eval(), so static analysis does not
     * see them: the name is built dynamically and marked as class-string.
     *
     * Стаб-классы создаются через eval(), поэтому статический анализ их не
     * видит: имя собирается динамически и помечается как class-string.
     *
     * @return class-string
     */
    private function stubClass(string $shortName): string
    {
        /** @var class-string $class */
        $class = self::STUB_NS . "\\" . $shortName;

        return $class;
    }

    /**
     * @param FilterRule[] $filterRules
     * @param string $origin base name of the stub class set
     * @param bool $withUserReadLocator whether the target model has a read action with a locator
     * @param string|null $userModelLocator impl.models.vUser.entityLocator
     * @return array{0: Model[], 1: Action[]}
     */
    private function makeConfig(
        array $filterRules,
        string $origin = "VGarden",
        bool $withUserReadLocator = true,
        ?string $userModelLocator = null,
    ): array {
        $user = new Model(
            class: self::STUB_NS . "\\VGUser",
            type: "vUser",
            attributes: [new Attribute(name: "id", type: "int")],
            relations: [],
            entityLocator: $userModelLocator,
        );

        $garden = new Model(
            class: self::STUB_NS . "\\" . $origin,
            type: "vGarden",
            attributes: [new Attribute(name: "id", type: "int")],
            relations: [
                new Relation(
                    name: "user",
                    type: Relation::MANY_TO_ONE,
                    targetModel: "vUser",
                ),
            ],
        );

        $actions = [
            new Action(
                type: ActionType::List,
                model: "vGarden",
                config: new ListActionConfig(
                    class: $this->stubClass($origin . "Query"),
                    pagination: new PaginationRule(
                        maxSize: 100,
                        defaultSize: 20,
                    ),
                    sort: [],
                    filters: $filterRules,
                ),
            ),
        ];

        if ($withUserReadLocator) {
            $actions[] = new Action(
                type: ActionType::Read,
                model: "vUser",
                entityLocator: "doctrine",
            );
        }

        return [[$garden, $user], $actions];
    }

    // ==================== Valid config ====================
    //
    // ==================== Валидный конфиг ====================

    public function testRelationRuleWithEqualAndInPasses(): void
    {
        [$models, $actions] = $this->makeConfig([
            new FilterRule(
                path: "user",
                operators: [
                    FilterOperator::Equal,
                    new FilterOperatorConfig(FilterOperator::In),
                ],
            ),
        ]);

        $this->validator->validateListFilters($models, $actions);

        $this->addToAssertionCount(1);
    }

    // ==================== Restricting operators ====================
    //
    // ==================== Ограничение операторов ====================

    public function testRelationRuleWithLikeThrows(): void
    {
        [$models, $actions] = $this->makeConfig([
            new FilterRule(
                path: "user",
                operators: [FilterOperator::Equal, FilterOperator::Like],
            ),
        ]);

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'user' of model 'vGarden' resolves to a relation and supports only 'equal', 'in' operators, " .
            "'like' given",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    public function testRelationRuleWithRangeThrows(): void
    {
        [$models, $actions] = $this->makeConfig([
            new FilterRule(path: "user", operators: [FilterOperator::Range]),
        ]);

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'user' of model 'vGarden' resolves to a relation and supports only 'equal', 'in' operators, " .
            "'range' given",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    // ==================== Target model locator ====================
    //
    // ==================== Локатор целевой модели ====================

    public function testRelationRuleWithoutTargetReadLocatorThrows(): void
    {
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            withUserReadLocator: false,
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'user' of model 'vGarden' resolves to relation target 'vUser' which has no entity locator",
        );

        $this->validator->validateListFilters($models, $actions);
    }

    /**
     * The target model's locator can be declared without a read action: on the model or
     * via impl.defaultLocator.
     *
     * Локатор целевой модели можно объявить без read-действия: у модели или
     * через impl.defaultLocator.
     */
    public function testRelationRuleWithModelOrDefaultLocatorPasses(): void
    {
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            withUserReadLocator: false,
            userModelLocator: "doctrine",
        );
        $this->validator->validateListFilters($models, $actions);

        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            withUserReadLocator: false,
        );
        $this->validator->validateListFilters($models, $actions, "doctrine");

        $this->addToAssertionCount(2);
    }

    // ==================== Property name in the DTO ====================
    //
    // ==================== Имя свойства в DTO ====================

    public function testRelationRuleRequiresOwnPropertyInOperatorClass(): void
    {
        // The DTO has a scalar relUserRelId (path user.id), while the rule
        // is declared on the whole relation — it needs the user property
        //
        // В DTO лежит скалярное relUserRelId (путь user.id), а правило
        // объявлено на связь целиком — ему нужно свойство user
        [$models, $actions] = $this->makeConfig(
            [new FilterRule(path: "user", operators: [FilterOperator::Equal])],
            origin: "VScalar",
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Filter rule 'user' of model 'vGarden' has no matching property 'user' in class '" .
                self::STUB_NS .
                "\\VScalarEqual'",
        );

        $this->validator->validateListFilters($models, $actions);
    }
}
