<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Constraint;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\ActionType;
use UniMethod\Frame\Config\Common\Constraints\{
    ConstraintProperty,
    ConstraintRule,
};
use UniMethod\Frame\Constraint\{ConstraintValidator, RuleCatalog, RuleType};
use UniMethod\Frame\Constraint\ConstraintViolation;
use UniMethod\Frame\Type\{TypeDef, TypeRegistry, TypeSpec, ValueKind};

final class ConstraintValidatorTest extends TestCase
{
    private ConstraintValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ConstraintValidator(
            new RuleCatalog(CatalogData::full()),
            new TypeRegistry([
                new TypeDef(
                    name: 'money',
                    kind: ValueKind::Int,
                    rules: [new ConstraintRule(RuleType::Range, ['min' => 0])],
                ),
                new TypeDef(
                    name: 'order_status',
                    kind: ValueKind::String,
                    enum: ['new', 'paid', 'shipped'],
                ),
            ]),
        );
    }

    // =============== NULL / notNull ===============

    public function testNullWithNotNullRuleProducesViolation(): void
    {
        $violations = $this->validator->validate(
            null,
            new TypeSpec(ValueKind::String, nullable: true),
            new ConstraintProperty('email', rules: [new ConstraintRule(RuleType::NotNull)]),
            null,
            'email',
        );

        $this->assertCount(1, $violations);
        $this->assertSame(RuleType::NotNull, $violations[0]->rule);
        $this->assertSame('email', $violations[0]->path);
    }

    public function testNullWithoutNotNullIsValid(): void
    {
        $violations = $this->validator->validate(
            null,
            new TypeSpec(ValueKind::String, nullable: true),
            new ConstraintProperty('email', rules: [
                new ConstraintRule(RuleType::Length, ['max' => 10]),
            ]),
            null,
            'email',
        );

        $this->assertSame([], $violations);
    }

    public function testValueRulesSkippedForNull(): void
    {
        // Value rules are applied only to non-null (matrix semantics)
        //
        // Правила значения применяются только к не-null (семантика матрицы)
        $violations = $this->validator->validate(
            null,
            new TypeSpec(ValueKind::String, nullable: true),
            new ConstraintProperty('email', rules: [
                new ConstraintRule(RuleType::Email),
                new ConstraintRule(RuleType::NotBlank),
            ]),
            null,
            'email',
        );

        $this->assertSame([], $violations);
    }

    // =============== VALUE RULES ===============
    //
    // =============== ПРАВИЛА ЗНАЧЕНИЯ ===============

    public function testNotBlank(): void
    {
        $property = new ConstraintProperty('name', rules: [new ConstraintRule(RuleType::NotBlank)]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertCount(1, $this->validator->validate('   ', $spec, $property, null, 'name'));
        $this->assertSame([], $this->validator->validate('John', $spec, $property, null, 'name'));
    }

    public function testLengthMinMax(): void
    {
        $property = new ConstraintProperty('name', rules: [
            new ConstraintRule(RuleType::Length, ['min' => 2, 'max' => 5]),
        ]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertCount(1, $this->validator->validate('a', $spec, $property, null, 'name'));
        $this->assertCount(1, $this->validator->validate('abcdef', $spec, $property, null, 'name'));
        $this->assertSame([], $this->validator->validate('abc', $spec, $property, null, 'name'));
    }

    public function testLengthUsesMultibyteSemantics(): void
    {
        $property = new ConstraintProperty('name', rules: [
            new ConstraintRule(RuleType::Length, ['max' => 4]),
        ]);

        // 4 Cyrillic characters — not 8 bytes
        //
        // 4 кириллических символа — не 8 байт
        $this->assertSame(
            [],
            $this->validator->validate('Иван', new TypeSpec(ValueKind::String), $property, null, 'name'),
        );
    }

    public function testRegex(): void
    {
        $property = new ConstraintProperty('slug', rules: [
            new ConstraintRule(RuleType::Regex, ['pattern' => '/^[a-z-]+$/']),
        ]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame([], $this->validator->validate('my-slug', $spec, $property, null, 'slug'));
        $this->assertCount(1, $this->validator->validate('My Slug', $spec, $property, null, 'slug'));
    }

    public function testEmail(): void
    {
        $property = new ConstraintProperty('email', rules: [new ConstraintRule(RuleType::Email)]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame([], $this->validator->validate('a@b.cd', $spec, $property, null, 'email'));
        $this->assertCount(1, $this->validator->validate('not-an-email', $spec, $property, null, 'email'));
    }

    public function testUrl(): void
    {
        $property = new ConstraintProperty('site', rules: [new ConstraintRule(RuleType::Url)]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame([], $this->validator->validate('https://example.com', $spec, $property, null, 'site'));
        $this->assertCount(1, $this->validator->validate('example', $spec, $property, null, 'site'));
    }

    public function testRangeForInt(): void
    {
        $property = new ConstraintProperty('age', rules: [
            new ConstraintRule(RuleType::Range, ['min' => 18, 'max' => 120]),
        ]);
        $spec = new TypeSpec(ValueKind::Int);

        $this->assertCount(1, $this->validator->validate(17, $spec, $property, null, 'age'));
        $this->assertCount(1, $this->validator->validate(121, $spec, $property, null, 'age'));
        $this->assertSame([], $this->validator->validate(18, $spec, $property, null, 'age'));
    }

    public function testRangeForDatetimeComparesInstantsAgainstStringBounds(): void
    {
        $property = new ConstraintProperty('publishedAt', rules: [
            new ConstraintRule(RuleType::Range, ['max' => '2025-12-31']),
        ]);
        $spec = new TypeSpec(ValueKind::DateTime);

        $this->assertCount(1, $this->validator->validate(
            new DateTimeImmutable('2026-01-15'),
            $spec,
            $property,
            null,
            'publishedAt',
        ));
        $this->assertSame([], $this->validator->validate(
            new DateTimeImmutable('2025-06-01'),
            $spec,
            $property,
            null,
            'publishedAt',
        ));
    }

    public function testChoiceIsStrict(): void
    {
        $property = new ConstraintProperty('status', rules: [
            new ConstraintRule(RuleType::Choice, ['values' => ['new', 'paid']]),
        ]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame([], $this->validator->validate('new', $spec, $property, null, 'status'));
        $this->assertCount(1, $this->validator->validate('unknown', $spec, $property, null, 'status'));
    }

    public function testEqualsForConsentFlag(): void
    {
        $property = new ConstraintProperty('agreedToTerms', create: [
            new ConstraintRule(RuleType::Equals, ['value' => true]),
        ]);
        $spec = new TypeSpec(ValueKind::Bool);

        $this->assertCount(1, $this->validator->validate(false, $spec, $property, ActionType::Create, 'agreedToTerms'));
        $this->assertSame([], $this->validator->validate(true, $spec, $property, ActionType::Create, 'agreedToTerms'));
    }

    // =============== CONTEXT MERGE ===============
    //
    // =============== СЛИЯНИЕ КОНТЕКСТОВ ===============

    public function testCreateRulesAppliedOnlyInCreateContext(): void
    {
        $property = new ConstraintProperty('agreedToTerms', create: [
            new ConstraintRule(RuleType::Equals, ['value' => true]),
        ]);
        $spec = new TypeSpec(ValueKind::Bool);

        $this->assertSame([], $this->validator->validate(false, $spec, $property, null, 'agreedToTerms'));
        $this->assertSame([], $this->validator->validate(false, $spec, $property, ActionType::Update, 'agreedToTerms'));
        $this->assertCount(1, $this->validator->validate(false, $spec, $property, ActionType::Create, 'agreedToTerms'));
    }

    public function testContextRulesAddToBaseRulesNotReplace(): void
    {
        $property = new ConstraintProperty(
            'name',
            rules: [new ConstraintRule(RuleType::Length, ['max' => 3])],
            create: [new ConstraintRule(RuleType::NotBlank)],
        );
        $spec = new TypeSpec(ValueKind::String);

        // In create both the base (length) and the contextual (blank after trim — no, here it is length) are violated
        //
        // В create нарушены и базовое (длина), и контекстное (blank после trim — нет, тут длина)
        $violations = $this->validator->validate('abcd', $spec, $property, ActionType::Create, 'name');
        $this->assertCount(1, $violations);
        $this->assertSame(RuleType::Length, $violations[0]->rule);

        // Both at once: an empty string passes length, fails notBlank; a long one — the other way around
        //
        // Оба сразу: пустая строка проходит length, падает notBlank; длинная — наоборот
        $violations = $this->validator->validate('', $spec, $property, ActionType::Create, 'name');
        $this->assertCount(1, $violations);
        $this->assertSame(RuleType::NotBlank, $violations[0]->rule);
    }

    public function testUpdateRulesAppliedOnlyInUpdateContext(): void
    {
        $property = new ConstraintProperty('name', update: [
            new ConstraintRule(RuleType::NotBlank),
        ]);
        $spec = new TypeSpec(ValueKind::String);

        $this->assertSame([], $this->validator->validate('', $spec, $property, ActionType::Create, 'name'));
        $this->assertCount(1, $this->validator->validate('', $spec, $property, ActionType::Update, 'name'));
    }

    // =============== COLLECTIONS ===============
    //
    // =============== КОЛЛЕКЦИИ ===============

    public function testCountOnList(): void
    {
        $property = new ConstraintProperty('tags', rules: [
            new ConstraintRule(RuleType::Count, ['min' => 1, 'max' => 2]),
        ]);
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $this->assertCount(1, $this->validator->validate([], $spec, $property, null, 'tags'));
        $this->assertCount(1, $this->validator->validate(['a', 'b', 'c'], $spec, $property, null, 'tags'));
        $this->assertSame([], $this->validator->validate(['a'], $spec, $property, null, 'tags'));
    }

    public function testUniqueItems(): void
    {
        $property = new ConstraintProperty('tags', rules: [
            new ConstraintRule(RuleType::UniqueItems),
        ]);
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $this->assertCount(1, $this->validator->validate(['a', 'b', 'a'], $spec, $property, null, 'tags'));
        $this->assertSame([], $this->validator->validate(['a', 'b'], $spec, $property, null, 'tags'));
    }

    public function testEachAppliesValueRulesPerItemWithIndexedPath(): void
    {
        $property = new ConstraintProperty('tags', each: [
            new ConstraintRule(RuleType::Length, ['max' => 3]),
        ]);
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $violations = $this->validator->validate(['ok', 'too-long', 'ok2'], $spec, $property, null, 'tags');

        $this->assertCount(1, $violations);
        $this->assertSame('tags[1]', $violations[0]->path);
        $this->assertSame(RuleType::Length, $violations[0]->rule);
    }

    public function testCollectionAndEachViolationsAreCombined(): void
    {
        $property = new ConstraintProperty(
            'tags',
            rules: [new ConstraintRule(RuleType::Count, ['max' => 2])],
            each: [new ConstraintRule(RuleType::Length, ['max' => 1])],
        );
        $spec = new TypeSpec(ValueKind::String, isList: true);

        $violations = $this->validator->validate(['aa', 'b', 'cc'], $spec, $property, null, 'tags');

        $rules = array_map(static fn (ConstraintViolation $v): RuleType => $v->rule, $violations);
        $this->assertContains(RuleType::Count, $rules);
        $this->assertContains(RuleType::Length, $rules);
        $this->assertCount(3, $violations); // count + tags[0] + tags[2]
    }

    // =============== DECLARED TYPES (registry) ===============
    //
    // =============== ОБЪЯВЛЕННЫЕ ТИПЫ (реестр) ===============

    public function testEnumTypeGetsImplicitChoiceRule(): void
    {
        $property = new ConstraintProperty('status');
        $spec = new TypeSpec(ValueKind::String, declaredName: 'order_status');

        $violations = $this->validator->validate('cancelled', $spec, $property, null, 'status');

        $this->assertCount(1, $violations);
        $this->assertSame(RuleType::Choice, $violations[0]->rule);
        $this->assertSame([], $this->validator->validate('paid', $spec, $property, null, 'status'));
    }

    public function testDeclaredTypeImplicitRulesAlwaysApply(): void
    {
        // money: implicit range(min: 0) from the type declaration
        //
        // money: неявный range(min: 0) из объявления типа
        $property = new ConstraintProperty('price');
        $spec = new TypeSpec(ValueKind::Int, declaredName: 'money');

        $violations = $this->validator->validate(-100, $spec, $property, null, 'price');

        $this->assertCount(1, $violations);
        $this->assertSame(RuleType::Range, $violations[0]->rule);
        $this->assertSame([], $this->validator->validate(100, $spec, $property, null, 'price'));
    }

    public function testDeclaredTypeRulesApplyPerItemInLists(): void
    {
        $property = new ConstraintProperty('prices');
        $spec = new TypeSpec(ValueKind::Int, isList: true, declaredName: 'money');

        $violations = $this->validator->validate([100, -5], $spec, $property, null, 'prices');

        $this->assertCount(1, $violations);
        $this->assertSame('prices[1]', $violations[0]->path);
    }

    public function testDeclaredTypeRulesCombineWithPropertyRules(): void
    {
        $property = new ConstraintProperty('price', rules: [
            new ConstraintRule(RuleType::Range, ['max' => 1000]),
        ]);
        $spec = new TypeSpec(ValueKind::Int, declaredName: 'money');

        // Implicit min:0 and explicit max:1000 apply simultaneously
        //
        // Неявное min:0 и явное max:1000 действуют одновременно
        $this->assertCount(1, $this->validator->validate(-1, $spec, $property, null, 'price'));
        $this->assertCount(1, $this->validator->validate(2000, $spec, $property, null, 'price'));
        $this->assertSame([], $this->validator->validate(500, $spec, $property, null, 'price'));
    }

    // =============== MISC ===============
    //
    // =============== РАЗНОЕ ===============

    public function testNoRulesMeansNoViolations(): void
    {
        $violations = $this->validator->validate(
            'anything',
            new TypeSpec(ValueKind::String),
            new ConstraintProperty('name'),
            ActionType::Create,
            'name',
        );

        $this->assertSame([], $violations);
    }

    public function testMultipleViolationsReportedTogether(): void
    {
        $property = new ConstraintProperty('email', rules: [
            new ConstraintRule(RuleType::Length, ['min' => 10]),
            new ConstraintRule(RuleType::Email),
        ]);

        $violations = $this->validator->validate(
            'bad',
            new TypeSpec(ValueKind::String),
            $property,
            null,
            'email',
        );

        $this->assertCount(2, $violations);
    }
}
