<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

use UniMethod\Frame\Config\Common\Constraints\{ConstraintProperty, ConstraintRule, ConstraintsConfig};
use UniMethod\Frame\Config\Common\Schema\{SchemaConfig, SchemaModel, SchemaRelation};
use UniMethod\Frame\Type\{TypeSpec, TypeSpecParser, ValueKind};

final class ConstraintsConfigValidator
{
    public function __construct(
        private readonly RuleCatalog $catalog,
        private readonly TypeSpecParser $parser,
    ) {
    }

    /**
     * @return string[] Configuration errors; an empty array — the config is valid
     */
    public function validate(ConstraintsConfig $constraints, SchemaConfig $schema): array
    {
        $errors = [];
        $models = [];
        foreach ($schema->models as $model) {
            $models[$model->type] = $model;
        }

        foreach ($constraints->models as $cModel) {
            $sModel = $models[$cModel->model] ?? null;
            if ($sModel === null) {
                $errors[] = sprintf('constraints: unknown model "%s"', $cModel->model);
                continue;
            }

            $errors = [
                ...$errors,
                ...$this->validateAttributes($cModel->model, $cModel->attributes, $sModel),
                ...$this->validateRelations($cModel->model, $cModel->relations, $sModel),
            ];
        }

        return $errors;
    }

    /**
     * @param ConstraintProperty[] $properties
     * @return string[]
     */
    private function validateAttributes(string $model, array $properties, SchemaModel $sModel): array
    {
        $attributes = [];
        foreach ($sModel->attributes as $attribute) {
            $attributes[$attribute->name] = $attribute;
        }

        $errors = [];

        foreach ($properties as $property) {
            $where = sprintf('constraints: %s.%s', $model, $property->name);

            $attribute = $attributes[$property->name] ?? null;
            if ($attribute === null) {
                $errors[] = $where . ': unknown attribute';
                continue;
            }

            $spec = $this->parser->parse($attribute->type);

            $errors = [...$errors, ...$this->checkRules($property, $spec, isRelation: false, where: $where)];

            if ($property->each !== [] && !$spec->isList) {
                $errors[] = $where . ': "each" is only allowed on list types';
            }
        }

        return $errors;
    }

    /**
     * @param ConstraintProperty[] $properties
     * @return string[]
     */
    private function validateRelations(string $model, array $properties, SchemaModel $sModel): array
    {
        $relations = [];
        foreach ($sModel->relations as $relation) {
            $relations[$relation->name] = $relation;
        }

        $errors = [];

        foreach ($properties as $property) {
            $where = sprintf('constraints: %s.%s', $model, $property->name);

            $relation = $relations[$property->name] ?? null;
            if ($relation === null) {
                $errors[] = $where . ': unknown relation';
                continue;
            }

            if ($property->each !== []) {
                $errors[] = $where . ': "each" is not allowed on relations (target model owns its constraints)';
            }

            $errors = [
                ...$errors,
                ...$this->checkRules($property, $this->relationSpec($relation), isRelation: true, where: $where),
            ];
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    private function checkRules(ConstraintProperty $property, TypeSpec $spec, bool $isRelation, string $where): array
    {
        $errors = [];
        $groups = [
            'rules' => $property->rules,
            'create' => $property->create,
            'update' => $property->update,
        ];

        foreach ($groups as $group => $rules) {
            foreach ($rules as $rule) {
                if (!$this->catalog->applies($rule->type, $spec, $isRelation)) {
                    $errors[] = sprintf(
                        '%s (%s): rule "%s" is not applicable to this type',
                        $where,
                        $group,
                        $rule->type->value,
                    );
                }

                $errors = [...$errors, ...$this->checkParams($rule, $where, $group)];
            }
        }

        $itemSpec = new TypeSpec($spec->kind, nullable: false, isList: false);
        foreach ($property->each as $rule) {
            if (!$this->catalog->applies($rule->type, $itemSpec, isRelation: false)) {
                $errors[] = sprintf(
                    '%s (each): rule "%s" is not applicable to the item type',
                    $where,
                    $rule->type->value,
                );
            }

            $errors = [...$errors, ...$this->checkParams($rule, $where, 'each')];
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    private function checkParams(ConstraintRule $rule, string $where, string $group): array
    {
        $errors = [];

        foreach ($this->catalog->get($rule->type)->params as $name => $paramSpec) {
            $required = !str_ends_with($paramSpec, '?');
            if ($required && !array_key_exists($name, $rule->params)) {
                $errors[] = sprintf(
                    '%s (%s): rule "%s" requires param "%s"',
                    $where,
                    $group,
                    $rule->type->value,
                    $name,
                );
            }
        }

        return $errors;
    }

    /**
     * A relation as a type spec: toMany → list, toOne → nullable single (being required is an explicit notNull).
     *
     * Связь как type-спек: toMany → list, toOne → nullable single (обязательность — явный notNull).
     */
    private function relationSpec(SchemaRelation $relation): TypeSpec
    {
        $isList = in_array($relation->type, ['oneToMany', 'manyToMany'], true);

        // kind is not used by the matrix for relations (relations:true rules have kinds:"*")
        //
        // kind для связей матрицей не используется (relations:true правила имеют kinds:"*")
        return new TypeSpec(ValueKind::String, nullable: !$isList, isList: $isList);
    }
}
