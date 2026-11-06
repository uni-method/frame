<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mutator;

use UniMethod\Frame\Config\{ActionType, Config, Model, PhaseType, Relation};
use UniMethod\Frame\Exception\{ConfigAlreadySetException, ConfigNotSetException};
use UniMethod\Frame\Mapping\AttributeAccessor;

class MutatorRegistry
{
    private Config|null $config;

    /**
     * @param array<string, AttributeMutatorInterface> $map
     * @param array<string, RelationMutatorInterface> $relationMap
     */
    public function __construct(
        private readonly array $map,
        private readonly AttributeAccessor $attributeAccessor,
        private readonly array $relationMap = [],
        ?Config $config = null,
    ) {
        $this->config = $config;
    }

    /**
     * The config is set once: here or in the constructor.
     *
     * Конфиг задаётся один раз: здесь либо в конструкторе.
     */
    public function setConfig(Config $config): void
    {
        if ($this->config instanceof Config) {
            throw new ConfigAlreadySetException(self::class);
        }

        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $includeTree
     */
    public function applyPhase(
        PhaseType $phase,
        object $entity,
        Model $model,
        ActionType $actionType,
        array $includeTree = [],
    ): void {
        // 1. Apply mutators to all attributes of the current model
        //
        // 1. Применяем мутаторы ко всем атрибутам текущей модели
        foreach ($model->attributes as $attr) {
            $mutators = $attr->mutators?->getForPhase($phase) ?? [];
            if ($mutators === []) {
                continue;
            }

            $getter = $this->attributeAccessor->resolveGetter(
                $entity,
                $attr->getter,
                $attr->name,
            );
            $setterSource =
                $attr->mutatorSetter !== null
                    ? $attr->mutatorSetter
                    : $attr->setter;
            $setter = $this->attributeAccessor->resolveSetter(
                $entity,
                $setterSource,
                $attr->name,
            );

            if (
                $getter === false ||
                $getter === null ||
                $setter === false ||
                $setter === null
            ) {
                continue;
            }

            $value = $this->attributeAccessor->getValue($entity, $getter);

            foreach ($mutators as $alias) {
                $manipulator = $this->map[$alias] ?? null;
                if ($manipulator === null) {
                    continue;
                }

                $value = $manipulator->apply(
                    $phase,
                    $entity,
                    $model,
                    $attr->name,
                    $value,
                    $actionType,
                );
            }

            $this->attributeAccessor->setValue($entity, $setter, $value);
        }

        // 2. Apply mutators to all relations of the current model
        //
        // 2. Применяем мутаторы ко всем связям текущей модели
        foreach ($model->relations as $relation) {
            $mutators = $relation->mutators?->getForPhase($phase) ?? [];
            if ($mutators === []) {
                continue;
            }

            $getter = $this->attributeAccessor->resolveGetter(
                $entity,
                $relation->getter,
                $relation->name,
            );
            $setterSource =
                $relation->mutatorSetter !== null
                    ? $relation->mutatorSetter
                    : $relation->setter;
            $setter = $this->attributeAccessor->resolveSetter(
                $entity,
                $setterSource,
                $relation->name,
            );

            if (
                $getter === false ||
                $getter === null ||
                $setter === false ||
                $setter === null
            ) {
                continue;
            }

            $value = $this->attributeAccessor->getValue($entity, $getter);

            foreach ($mutators as $alias) {
                $manipulator = $this->relationMap[$alias] ?? null;
                if ($manipulator === null) {
                    continue;
                }

                $value = $manipulator->apply(
                    $phase,
                    $entity,
                    $model,
                    $relation->name,
                    $value,
                    $actionType,
                );
            }

            $this->attributeAccessor->setValue($entity, $setter, $value);
        }

        // 3. Recursively walk the relations from includeTree
        //
        // 3. Рекурсивно обходим relations из includeTree
        foreach ($includeTree as $relationName => $subtree) {
            $relation = $this->findRelation($model, $relationName);
            if (!$relation instanceof Relation) {
                continue;
            }

            $nestedTree = is_array($subtree) ? $subtree : [];

            // Read the relation value
            //
            // Читаем значение relation
            $getter = $this->attributeAccessor->resolveGetter(
                $entity,
                $relation->getter,
                $relation->name,
            );
            if ($getter === false || $getter === null) {
                continue;
            }

            $relatedValue = $this->attributeAccessor->getValue(
                $entity,
                $getter,
            );
            if ($relatedValue === null) {
                continue;
            }

            // Find the target model
            //
            // Находим целевую модель
            $targetModel = $this->findModelByType($relation->targetModel);
            if (!$targetModel instanceof Model) {
                continue;
            }

            // Apply mutators depending on the relation cardinality
            //
            // Применяем мутаторы в зависимости от кардинальности relation
            if (
                $relation->type === Relation::ONE_TO_ONE ||
                $relation->type === Relation::MANY_TO_ONE
            ) {
                $this->applyPhase(
                    $phase,
                    $relatedValue,
                    $targetModel,
                    $actionType,
                    $nestedTree,
                );
            } elseif (
                $relation->type === Relation::ONE_TO_MANY ||
                $relation->type === Relation::MANY_TO_MANY
            ) {
                if (is_iterable($relatedValue)) {
                    foreach ($relatedValue as $item) {
                        $this->applyPhase(
                            $phase,
                            $item,
                            $targetModel,
                            $actionType,
                            $nestedTree,
                        );
                    }
                }
            }
        }
    }

    private function findRelation(Model $model, string $name): ?Relation
    {
        foreach ($model->relations as $relation) {
            if ($relation->name === $name) {
                return $relation;
            }
        }

        return null;
    }

    private function findModelByType(string $type): ?Model
    {
        if (!$this->config instanceof Config) {
            throw new ConfigNotSetException(self::class);
        }

        foreach ($this->config->models as $model) {
            if ($model->type === $type) {
                return $model;
            }
        }

        return null;
    }
}
