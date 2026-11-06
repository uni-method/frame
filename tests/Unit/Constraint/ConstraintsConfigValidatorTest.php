<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Constraint;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\Common\Constraints\{
    ConstraintProperty,
    ConstraintRule,
    ConstraintsConfig,
    ConstraintsModel,
};
use UniMethod\Frame\Config\Common\Schema\{
    SchemaAttribute,
    SchemaConfig,
    SchemaModel,
    SchemaRelation,
};
use UniMethod\Frame\Constraint\{
    ConstraintsConfigValidator,
    RuleCatalog,
    RuleType,
};
use UniMethod\Frame\Type\{TypeDef, TypeRegistry, TypeSpecParser, ValueKind};

final class ConstraintsConfigValidatorTest extends TestCase
{
    private ConstraintsConfigValidator $validator;

    private SchemaConfig $schema;

    protected function setUp(): void
    {
        $registry = new TypeRegistry([
            new TypeDef(name: 'money', kind: ValueKind::Int),
        ]);

        $this->validator = new ConstraintsConfigValidator(
            new RuleCatalog(CatalogData::full()),
            new TypeSpecParser($registry),
        );

        $this->schema = new SchemaConfig(
            models: [
                new SchemaModel(
                    type: 'user',
                    attributes: [
                        new SchemaAttribute(name: 'id', type: 'int'),
                        new SchemaAttribute(name: 'email', type: 'string'),
                        new SchemaAttribute(name: 'nickname', type: 'string|null'),
                        new SchemaAttribute(name: 'tags', type: 'string[]'),
                        new SchemaAttribute(name: 'balance', type: 'money'),
                    ],
                    relations: [
                        new SchemaRelation(name: 'orders', type: 'oneToMany', targetModel: 'order'),
                        new SchemaRelation(name: 'profile', type: 'oneToOne', targetModel: 'profile'),
                    ],
                ),
            ],
        );
    }

    public function testValidConfigProducesNoErrors(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(
                model: 'user',
                attributes: [
                    new ConstraintProperty('email', rules: [
                        new ConstraintRule(RuleType::Email),
                        new ConstraintRule(RuleType::Length, ['max' => 255]),
                    ]),
                    new ConstraintProperty('nickname', rules: [
                        new ConstraintRule(RuleType::NotNull),
                    ]),
                    new ConstraintProperty(
                        'tags',
                        rules: [new ConstraintRule(RuleType::Count, ['max' => 10])],
                        each: [new ConstraintRule(RuleType::Length, ['max' => 30])],
                    ),
                    new ConstraintProperty('balance', rules: [
                        new ConstraintRule(RuleType::Range, ['min' => 0]),
                    ]),
                ],
                relations: [
                    new ConstraintProperty('profile', create: [
                        new ConstraintRule(RuleType::NotNull),
                    ]),
                    new ConstraintProperty('orders', rules: [
                        new ConstraintRule(RuleType::Count, ['max' => 100]),
                    ]),
                ],
            ),
        ]);

        $this->assertSame([], $this->validator->validate($constraints, $this->schema));
    }

    public function testEmptyConstraintsAreValid(): void
    {
        $this->assertSame(
            [],
            $this->validator->validate(new ConstraintsConfig(), $this->schema),
        );
    }

    // =============== KEY MATCHING ===============
    //
    // =============== СОПОСТАВЛЕНИЕ КЛЮЧЕЙ ===============

    public function testUnknownModelReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'ghost'),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('unknown model "ghost"', $errors[0]);
    }

    public function testUnknownAttributeReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('ghost', rules: [new ConstraintRule(RuleType::NotBlank)]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('user.ghost', $errors[0]);
        $this->assertStringContainsString('unknown attribute', $errors[0]);
    }

    public function testUnknownRelationReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', relations: [
                new ConstraintProperty('ghost', rules: [new ConstraintRule(RuleType::NotNull)]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('unknown relation', $errors[0]);
    }

    // =============== APPLICABILITY MATRIX ===============
    //
    // =============== МАТРИЦА ПРИМЕНИМОСТИ ===============

    public function testInapplicableRuleReported(): void
    {
        // email on int
        //
        // email на int
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('id', rules: [new ConstraintRule(RuleType::Email)]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('rule "email" is not applicable', $errors[0]);
    }

    public function testNotNullOnNonNullableAttributeReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('email', rules: [new ConstraintRule(RuleType::NotNull)]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('notNull', $errors[0]);
    }

    public function testGroupNameIncludedInError(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('id', create: [new ConstraintRule(RuleType::Email)]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('(create)', $errors[0]);
    }

    public function testDeclaredTypeResolvedThroughRegistry(): void
    {
        // range is applicable to money (kind: int); length is not
        //
        // range применим к money (kind: int); length — нет
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('balance', rules: [
                    new ConstraintRule(RuleType::Length, ['max' => 5]),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('rule "length" is not applicable', $errors[0]);
    }

    public function testValueRuleOnListAttributeReported(): void
    {
        // length on string[] without each
        //
        // length на string[] без each
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('tags', rules: [
                    new ConstraintRule(RuleType::Length, ['max' => 30]),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('not applicable', $errors[0]);
    }

    // =============== EACH ===============

    public function testEachOnNonListAttributeReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('email', each: [
                    new ConstraintRule(RuleType::Length, ['max' => 5]),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"each" is only allowed on list types', $errors[0]);
    }

    public function testEachOnRelationReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', relations: [
                new ConstraintProperty('orders', each: [
                    new ConstraintRule(RuleType::NotBlank),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('"each" is not allowed on relations', $errors[0]);
    }

    public function testEachRuleValidatedAgainstItemType(): void
    {
        // range on a string[] element — not applicable
        //
        // range на элемент string[] — не применим
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('tags', each: [
                    new ConstraintRule(RuleType::Range, ['min' => 0]),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('(each)', $errors[0]);
        $this->assertStringContainsString('not applicable to the item type', $errors[0]);
    }

    // =============== RELATIONS ===============
    //
    // =============== СВЯЗИ ===============

    public function testCountOnToOneRelationReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', relations: [
                new ConstraintProperty('profile', rules: [
                    new ConstraintRule(RuleType::Count, ['min' => 1]),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('rule "count" is not applicable', $errors[0]);
    }

    public function testNotNullOnToManyRelationReported(): void
    {
        // toMany — a list, not a nullable single; notNull is not applicable
        //
        // toMany — list, не nullable single; notNull не применим
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', relations: [
                new ConstraintProperty('orders', rules: [
                    new ConstraintRule(RuleType::NotNull),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('notNull', $errors[0]);
    }

    // =============== PARAMS ===============
    //
    // =============== ПАРАМЕТРЫ ===============

    public function testMissingRequiredParamReported(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('email', rules: [
                    // without pattern
                    //
                    // без pattern
                    new ConstraintRule(RuleType::Regex),
                ]),
            ]),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('requires param "pattern"', $errors[0]);
    }

    public function testOptionalParamsMayBeOmitted(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('email', rules: [
                    // min omitted
                    //
                    // min опущен
                    new ConstraintRule(RuleType::Length, ['max' => 255]),
                ]),
            ]),
        ]);

        $this->assertSame([], $this->validator->validate($constraints, $this->schema));
    }

    public function testAllErrorsCollectedNotJustFirst(): void
    {
        $constraints = new ConstraintsConfig(models: [
            new ConstraintsModel(model: 'user', attributes: [
                new ConstraintProperty('id', rules: [new ConstraintRule(RuleType::Email)]),
                new ConstraintProperty('ghost', rules: [new ConstraintRule(RuleType::NotBlank)]),
            ]),
            new ConstraintsModel(model: 'phantom'),
        ]);

        $errors = $this->validator->validate($constraints, $this->schema);

        $this->assertCount(3, $errors);
    }
}
