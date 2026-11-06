<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Constraint;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Constraint\{RuleCatalog, RuleScope, RuleType};
use UniMethod\Frame\Type\{TypeSpec, ValueKind};

final class RuleCatalogTest extends TestCase
{
    private RuleCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new RuleCatalog(CatalogData::full());
    }

    public function testGetReturnsDefWithParsedFields(): void
    {
        $def = $this->catalog->get(RuleType::Range);

        $this->assertSame(RuleType::Range, $def->type);
        $this->assertSame(RuleScope::Value, $def->scope);
        $this->assertSame([ValueKind::Int, ValueKind::Float, ValueKind::DateTime], $def->kinds);
        $this->assertFalse($def->relations);
        $this->assertFalse($def->requiresNullable);
        $this->assertSame(['min' => 'scalar?', 'max' => 'scalar?'], $def->params);
    }

    public function testWildcardKindsParsedAsNull(): void
    {
        $this->assertNull($this->catalog->get(RuleType::NotNull)->kinds);
        $this->assertNull($this->catalog->get(RuleType::Count)->kinds);
    }

    public function testValueRuleAppliesToMatchingKind(): void
    {
        $spec = new TypeSpec(ValueKind::String);

        $this->assertTrue($this->catalog->applies(RuleType::Email, $spec, isRelation: false));
        $this->assertTrue($this->catalog->applies(RuleType::Length, $spec, isRelation: false));
    }

    public function testValueRuleRejectsWrongKind(): void
    {
        $spec = new TypeSpec(ValueKind::Int);

        $this->assertFalse($this->catalog->applies(RuleType::Email, $spec, isRelation: false));
        $this->assertFalse($this->catalog->applies(RuleType::NotBlank, $spec, isRelation: false));
    }

    public function testRangeAppliesToIntFloatDatetimeOnly(): void
    {
        $this->assertTrue($this->catalog->applies(RuleType::Range, new TypeSpec(ValueKind::Int), false));
        $this->assertTrue($this->catalog->applies(RuleType::Range, new TypeSpec(ValueKind::Float), false));
        $this->assertTrue($this->catalog->applies(RuleType::Range, new TypeSpec(ValueKind::DateTime), false));
        $this->assertFalse($this->catalog->applies(RuleType::Range, new TypeSpec(ValueKind::Bool), false));
        $this->assertFalse($this->catalog->applies(RuleType::Range, new TypeSpec(ValueKind::String), false));
    }

    public function testEqualsAppliesToBool(): void
    {
        $this->assertTrue($this->catalog->applies(RuleType::Equals, new TypeSpec(ValueKind::Bool), false));
    }

    public function testValueRuleRejectsListType(): void
    {
        $spec = new TypeSpec(ValueKind::String, isList: true);

        // Per element — via each
        //
        // На элемент — через each
        $this->assertFalse($this->catalog->applies(RuleType::Length, $spec, isRelation: false));
    }

    public function testCollectionRuleRequiresListType(): void
    {
        $this->assertTrue(
            $this->catalog->applies(RuleType::Count, new TypeSpec(ValueKind::String, isList: true), false),
        );
        $this->assertFalse($this->catalog->applies(RuleType::Count, new TypeSpec(ValueKind::String), false));
    }

    public function testNotNullRequiresNullableType(): void
    {
        $this->assertTrue(
            $this->catalog->applies(RuleType::NotNull, new TypeSpec(ValueKind::String, nullable: true), false),
        );
        $this->assertFalse($this->catalog->applies(RuleType::NotNull, new TypeSpec(ValueKind::String), false));
    }

    public function testRelationOnlyAcceptsRelationEnabledRules(): void
    {
        $toOne = new TypeSpec(ValueKind::String, nullable: true);
        $toMany = new TypeSpec(ValueKind::String, isList: true);

        $this->assertTrue($this->catalog->applies(RuleType::NotNull, $toOne, isRelation: true));
        $this->assertTrue($this->catalog->applies(RuleType::Count, $toMany, isRelation: true));
        $this->assertFalse($this->catalog->applies(RuleType::NotBlank, $toOne, isRelation: true));
        $this->assertFalse($this->catalog->applies(RuleType::UniqueItems, $toMany, isRelation: true));
    }

    public function testKindsIgnoredForRelations(): void
    {
        // kind of a relation is synthetic; rules with relations:true have kinds:"*"
        //
        // kind у связи синтетический; правила с relations:true имеют kinds:"*"
        $spec = new TypeSpec(ValueKind::String, nullable: true);

        $this->assertTrue($this->catalog->applies(RuleType::NotNull, $spec, isRelation: true));
    }
}
