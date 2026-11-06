<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use UniMethod\Frame\Config\{Attribute, Model, Relation};
use UniMethod\Frame\Mapping\MappingToListAction;
use UniMethod\Frame\Type\TypeSpecParser;

final class MappingToListActionFindAttributeRelationTest extends TestCase
{
    private MappingToListAction $mapper;

    protected function setUp(): void
    {
        $this->mapper = new MappingToListAction(new TypeSpecParser());
    }

    private function callFindAttribute(Model $model, string $name): Attribute
    {
        $method = (new ReflectionClass($this->mapper))->getMethod(
            "findAttribute",
        );

        /** @var Attribute $attribute */
        $attribute = $method->invoke($this->mapper, $model, $name);

        return $attribute;
    }

    private function callFindRelation(Model $model, string $name): Relation
    {
        $method = (new ReflectionClass($this->mapper))->getMethod("findRelation");

        /** @var Relation $relation */
        $relation = $method->invoke($this->mapper, $model, $name);

        return $relation;
    }

    /**
     * @param list<Attribute> $attributes
     * @param list<Relation> $relations
     */
    private function makeModel(
        string $type,
        array $attributes = [],
        array $relations = [],
    ): Model {
        return new Model(
            class: "TestClass",
            type: $type,
            attributes: $attributes,
            relations: $relations,
        );
    }

    // ==================== findAttribute ====================

    public function testFindAttributeThrowsWhenNotFound(): void
    {
        $model = $this->makeModel("product");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Attribute 'name' not found in model 'product'",
        );

        $this->callFindAttribute($model, "name");
    }

    public function testFindAttributeThrowsWithCorrectModelType(): void
    {
        $model = $this->makeModel("order", [
            new Attribute(
                name: "id",
                type: "int",
                getter: "getId",
                constructorParam: false,
            ),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Attribute 'missing' not found in model 'order'",
        );

        $this->callFindAttribute($model, "missing");
    }

    public function testFindAttributeReturnsMatchingAttribute(): void
    {
        $attribute = new Attribute(
            name: "name",
            type: "string",
            getter: "getName",
            constructorParam: false,
        );
        $model = $this->makeModel("product", [$attribute]);

        $result = $this->callFindAttribute($model, "name");

        $this->assertSame($attribute, $result);
    }

    // ==================== findRelation ====================

    public function testFindRelationThrowsWhenNotFound(): void
    {
        $model = $this->makeModel("product");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Relation 'owner' not found in model 'product'",
        );

        $this->callFindRelation($model, "owner");
    }

    public function testFindRelationThrowsWithCorrectModelType(): void
    {
        $model = $this->makeModel("order");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Relation 'missing' not found in model 'order'",
        );

        $this->callFindRelation($model, "missing");
    }

    public function testFindRelationReturnsMatchingRelation(): void
    {
        $relation = new Relation(
            name: "owner",
            type: Relation::ONE_TO_MANY,
            targetModel: "user",
        );
        $model = $this->makeModel("product", [], [$relation]);

        $result = $this->callFindRelation($model, "owner");

        $this->assertSame($relation, $result);
    }
}
