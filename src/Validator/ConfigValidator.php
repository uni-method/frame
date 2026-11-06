<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Model,
    ModelLocatorResolver,
    Mutators,
    Relation,
};
use UniMethod\Frame\Config\Common\Impl\{ImplAttribute, ImplConfig};
use UniMethod\Frame\Config\Common\Schema\{SchemaConfig, SchemaWriteMode};
use UniMethod\Frame\Config\ListAction\{
    FilterOperator,
    FilterOperatorConfig,
    FilterRule,
    ListActionConfig,
};
use UniMethod\Frame\Config\ListAction\PaginationRule;
use UniMethod\Frame\Integration\UserInterface;
use UniMethod\Frame\Mapping\ListQuery\ListDtoClassNames;
use UniMethod\Frame\Mutator\{
    AttributeMutatorInterface,
    RelationMutatorInterface,
};
use UniMethod\Frame\Persistence\{
    EntityCollectionInterface,
    EntityLocatorInterface,
    EntityRemoverInterface,
    EntitySaverInterface,
};
use UniMethod\Frame\Type\{Caster, TypeSpec, TypeSpecParser, ValueKind};
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class ConfigValidator
{
    /**
     * Checks that each model defines an 'id' attribute with a getter
     * (null — auto-detection, false — reading is forbidden).
     *
     * Проверяет, что каждая модель определяет атрибут 'id' с getter-ом
     * (null — автоопределение, false — чтение запрещено).
     *
     * @param Model[] $models
     * @throws InvalidConfigStructureException
     */
    public function validateModelIds(array $models): void
    {
        foreach ($models as $model) {
            $idAttr = null;
            foreach ($model->attributes as $attr) {
                if ($attr->name === "id") {
                    $idAttr = $attr;
                    break;
                }
            }

            if (
                $idAttr === null ||
                $idAttr->getter === false ||
                (is_string($idAttr->getter) && trim($idAttr->getter) === "")
            ) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Model '%s' must define an attribute 'id' with a getter",
                        $model->type,
                    ),
                );
            }
        }
    }

    /**
     * Checks the consistency of list actions' config->filters with the schema and
     * the generated DTO classes. The config is the single source of truth
     * for filtering (see MappingToListAction::mapFilter), therefore:
     *
     * 1. Each rule must have at least one operator.
     * 2. The rule path must resolve to a schema attribute (through a chain of
     *    relations) or the rule must have an explicit typeSpec — otherwise at
     *    runtime there is nowhere to take the type from.
     * 3. For each operator of the rule: the operator class exists,
     *    the Filter class has a property for the operator, the operator class has
     *    a property for the path — otherwise the config declares a non-existent
     *    capability (the rule silently does not work).
     * 4. Reverse drift: each public property of each operator class
     *    must correspond to some rule — otherwise the DTO property
     *    is dead (after switching to the whitelist it is not filterable).
     *
     * Checks 3–4 require the generated classes; if the root class of the
     * list action has not been generated yet, they are skipped (the class generator
     * loads the config itself — a hard requirement would break the first generation).
     *
     * Проверяет согласованность config->filters list-действий со схемой и
     * сгенерированными DTO-классами. Конфиг — единственный источник истины
     * для фильтрации (см. MappingToListAction::mapFilter), поэтому:
     *
     * 1. Каждое правило обязано иметь хотя бы один оператор.
     * 2. Путь правила обязан резолвиться в атрибут схемы (через цепочку
     *    реляций) либо правило обязано иметь явный typeSpec — иначе в
     *    рантайме тип взять неоткуда.
     * 3. Для каждого оператора правила: класс оператора существует,
     *    Filter-класс имеет свойство под оператор, класс оператора имеет
     *    свойство под путь — иначе конфиг декларирует несуществующую
     *    возможность (правило молча не работает).
     * 4. Обратный дрейф: каждое публичное свойство каждого класса оператора
     *    обязано соответствовать какому-то правилу — иначе свойство DTO
     *    мертво (после перехода на вайтлист оно нефильтруемо).
     *
     * Проверки 3–4 требуют сгенерированных классов; если корневой класс
     * list-действия ещё не сгенерирован, они пропускаются (генератор классов
     * сам загружает конфиг — жёсткое требование сломало бы первую генерацию).
     *
     * @param Model[] $models
     * @param Action[] $actions
     * @throws InvalidConfigStructureException
     */
    public function validateListFilters(
        array $models,
        array $actions,
        ?string $defaultLocator = null,
    ): void {
        $modelsByType = [];
        foreach ($models as $model) {
            $modelsByType[$model->type] = $model;
        }

        foreach ($actions as $action) {
            if (
                $action->type !== ActionType::List ||
                $action->config === null ||
                $action->config->class === null
            ) {
                continue;
            }

            $config = $action->config;

            $model = $modelsByType[$action->model] ?? null;
            if (!$model instanceof Model) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "List action refers to unknown model '%s'",
                        $action->model,
                    ),
                );
            }

            // Checks that do not require generated classes
            //
            // Проверки, не требующие сгенерированных классов
            foreach ($config->filters as $rule) {
                if ($rule->operators === []) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Filter rule '%s' of model '%s' must declare at least one operator",
                            $rule->path,
                            $model->type,
                        ),
                    );
                }

                // The path may point to a whole relation: then the filter accepts
                // the id of the target model, and the entity is put into the DTO
                //
                // Путь может указывать на связь целиком: тогда фильтр принимает
                // id целевой модели, а в DTO кладётся сущность
                $relationTarget = $this->filterPathResolvesToRelation(
                    $modelsByType,
                    $model,
                    $rule->path,
                );

                if ($relationTarget !== null) {
                    $this->validateRelationFilterRule(
                        $rule,
                        $model,
                        $relationTarget,
                        $models,
                        $actions,
                        $defaultLocator,
                    );

                    continue;
                }

                if (
                    !$rule->typeSpec instanceof TypeSpec &&
                    !$this->filterPathResolves(
                        $modelsByType,
                        $model,
                        $rule->path,
                    )
                ) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Filter rule '%s' of model '%s' does not resolve to a schema attribute and has no typeSpec",
                            $rule->path,
                            $model->type,
                        ),
                    );
                }
            }

            // Classes are not generated yet — reflective checks are impossible
            //
            // Классы ещё не сгенерированы — рефлексивные проверки невозможны
            if (!class_exists($config->class)) {
                continue;
            }

            $rootClassName = $config->class;
            $names = new ListDtoClassNames($rootClassName, $model->class);
            $filterClassName = $names->filter();

            if (
                $config->filters !== [] &&
                (!class_exists($filterClassName) || !property_exists($rootClassName, "filter"))
            ) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Model '%s' declares filter rules but filter class '%s' is missing or root class '%s' has no " .
                        "'filter' property",
                        $model->type,
                        $filterClassName,
                        $rootClassName,
                    ),
                );
            }

            $rulePropertyNames = [];

            foreach ($config->filters as $rule) {
                $propertyName = $this->filterPropertyName($rule->path);
                $rulePropertyNames[$propertyName] = true;

                foreach ($rule->operators as $item) {
                    $operator =
                        $item instanceof FilterOperatorConfig
                            ? $item->operator
                            : $item;

                    $operatorClassName = $names->operator($operator);

                    if (!class_exists($operatorClassName)) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "Filter rule '%s' of model '%s' declares operator '%s' but class '%s' is missing",
                                $rule->path,
                                $model->type,
                                $operator->value,
                                $operatorClassName,
                            ),
                        );
                    }

                    if (
                        !property_exists(
                            $filterClassName,
                            lcfirst($operator->name),
                        )
                    ) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "Filter class '%s' has no property '%s' for operator '%s' declared by rule '%s' of " .
                                "model '%s'",
                                $filterClassName,
                                lcfirst($operator->name),
                                $operator->value,
                                $rule->path,
                                $model->type,
                            ),
                        );
                    }

                    if (
                        !property_exists($operatorClassName, $propertyName)
                    ) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "Filter rule '%s' of model '%s' has no matching property '%s' in class '%s'",
                                $rule->path,
                                $model->type,
                                $propertyName,
                                $operatorClassName,
                            ),
                        );
                    }
                }
            }

            // Reverse drift: public non-static properties of operator
            // classes without a rule. Classes are not instantiated — user
            // code is not executed when the config is loaded.
            //
            // Обратный дрейф: публичные нестатические свойства операторных
            // классов без правила. Классы не инстанцируются — пользовательский
            // код при загрузке конфига не исполняется.
            foreach (FilterOperator::cases() as $operatorCase) {
                $operatorClassName = $names->operator($operatorCase);
                if (!class_exists($operatorClassName)) {
                    continue;
                }

                $propertyNames = [];
                foreach (
                    (new ReflectionClass($operatorClassName))->getProperties(
                        ReflectionProperty::IS_PUBLIC,
                    ) as $property
                ) {
                    if (!$property->isStatic()) {
                        $propertyNames[] = $property->getName();
                    }
                }

                foreach ($propertyNames as $propertyName) {
                    if (!isset($rulePropertyNames[$propertyName])) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "Property '%s' of class '%s' has no corresponding rule in 'filters' of model '%s' " .
                                "list action",
                                $propertyName,
                                $operatorClassName,
                                $model->type,
                            ),
                        );
                    }
                }
            }
        }
    }

    /**
     * Whether the filter path resolves to a schema attribute (through a chain of relations).
     * The logic is identical to MappingToListAction::resolveAttributeByPath.
     *
     * Резолвится ли путь фильтра в атрибут схемы (через цепочку реляций).
     * Логика идентична MappingToListAction::resolveAttributeByPath.
     *
     * @param array<string, Model> $modelsByType
     */
    private function filterPathResolves(
        array $modelsByType,
        Model $model,
        string $path,
    ): bool {
        return $this->resolveAttributeByPath($modelsByType, $model, $path)
            instanceof Attribute;
    }

    /**
     * The schema attribute by the filter path (through a chain of relations) or null.
     *
     * Атрибут схемы по пути фильтра (через цепочку реляций) либо null.
     *
     * @param array<string, Model> $modelsByType
     */
    private function resolveAttributeByPath(
        array $modelsByType,
        Model $model,
        string $path,
    ): ?Attribute {
        $segments = explode(".", $path);
        $currentModel = $model;

        // All segments except the last one are relations
        //
        // Все сегменты кроме последнего — реляции
        for ($i = 0; $i < count($segments) - 1; $i++) {
            $relation = null;
            foreach ($currentModel->relations as $r) {
                if ($r->name === $segments[$i]) {
                    $relation = $r;
                    break;
                }
            }

            if ($relation === null) {
                return null;
            }

            $currentModel = $modelsByType[$relation->targetModel] ?? null;
            if (!$currentModel instanceof Model) {
                return null;
            }
        }

        // The last segment is an attribute of the final model
        //
        // Последний сегмент — атрибут финальной модели
        foreach ($currentModel->attributes as $attr) {
            if ($attr->name === end($segments)) {
                return $attr;
            }
        }

        return null;
    }

    /**
     * Whether the filter path resolves to a whole relation (all segments are relations).
     * Returns the target model type or null.
     * The logic is identical to MappingToListAction::tryResolveRelationByPath.
     *
     * Резолвится ли путь фильтра в связь целиком (все сегменты — связи).
     * Возвращает тип целевой модели либо null.
     * Логика идентична MappingToListAction::tryResolveRelationByPath.
     *
     * @param array<string, Model> $modelsByType
     */
    private function filterPathResolvesToRelation(
        array $modelsByType,
        Model $model,
        string $path,
    ): ?string {
        $currentModel = $model;
        $targetType = null;

        foreach (explode(".", $path) as $segment) {
            $relation = null;
            foreach ($currentModel->relations as $candidate) {
                if ($candidate->name === $segment) {
                    $relation = $candidate;
                    break;
                }
            }

            if ($relation === null) {
                return null;
            }

            $targetType = $relation->targetModel;
            $currentModel = $modelsByType[$targetType] ?? null;
            if (!$currentModel instanceof Model) {
                return null;
            }
        }

        return $targetType;
    }

    /**
     * A rule on a whole relation: operators are limited to equal/in, and the target
     * model must have a locator (ModelLocatorResolver) — otherwise the runtime has
     * nothing to load the entity by the passed id with.
     *
     * Правило по связи целиком: операторы ограничены equal/in, а у целевой
     * модели обязан быть локатор (ModelLocatorResolver) — иначе рантайму
     * нечем грузить сущность по переданному id.
     *
     * @param Model[] $models
     * @param Action[] $actions
     * @throws InvalidConfigStructureException
     */
    private function validateRelationFilterRule(
        FilterRule $rule,
        Model $model,
        string $targetType,
        array $models,
        array $actions,
        ?string $defaultLocator,
    ): void {
        foreach ($rule->operators as $item) {
            $operator =
                $item instanceof FilterOperatorConfig
                    ? $item->operator
                    : $item;

            if (
                $operator !== FilterOperator::Equal &&
                $operator !== FilterOperator::In
            ) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Filter rule '%s' of model '%s' resolves to a relation and supports only 'equal', 'in' " .
                        "operators, '%s' given",
                        $rule->path,
                        $model->type,
                        $operator->value,
                    ),
                );
            }
        }

        if (ModelLocatorResolver::resolve($models, $actions, $defaultLocator, $targetType) !== null) {
            return;
        }

        throw new InvalidConfigStructureException(
            sprintf(
                "Filter rule '%s' of model '%s' resolves to relation target '%s' which has no entity locator. %s",
                $rule->path,
                $model->type,
                $targetType,
                ModelLocatorResolver::hint($targetType),
            ),
        );
    }

    /**
     * Converts the filter path into a DTO property name.
     * The logic is identical to MappingToListAction::resolvePropertyName.
     *
     * Преобразует путь фильтра в имя свойства DTO.
     * Логика идентична MappingToListAction::resolvePropertyName.
     */
    private function filterPropertyName(string $fieldPath): string
    {
        if (!str_contains($fieldPath, ".")) {
            return $fieldPath;
        }

        $segments = explode(".", $fieldPath);
        $pascalCased = array_map(
            static fn ($seg): string => ucfirst($seg),
            $segments,
        );

        return "rel" . implode("Rel", $pascalCased);
    }

    /**
     * Checks that boolean attributes do not use unsupported filters.
     *
     * Проверяет, что булевые атрибуты не используют неподдерживаемые фильтры.
     *
     * @param Model[] $models
     * @param Action[] $actions
     * @throws InvalidConfigStructureException
     */
    public function validateBoolFilters(array $models, array $actions): void
    {
        foreach ($actions as $action) {
            if (
                $action->type !== ActionType::List ||
                $action->config === null
            ) {
                continue;
            }

            foreach ($action->config->filters as $filter) {
                if (!$this->isBoolFilter($filter, $models, $action->model)) {
                    continue;
                }

                foreach ($filter->operators as $op) {
                    $operator =
                        $op instanceof FilterOperatorConfig
                            ? $op->operator
                            : $op;
                    if ($operator !== FilterOperator::Equal) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "Boolean filter '%s' in model '%s' supports only 'Equal' operator, '%s' given",
                                $filter->path,
                                $action->model,
                                $operator->value,
                            ),
                        );
                    }
                }
            }
        }
    }

    /**
     * Determines whether the filter is boolean by the effective field type.
     * The type source matches the runtime: if the filter has a typeSpec —
     * the model attribute is not looked up, the type is taken from TypeSpec; otherwise — from the attribute.
     *
     * Определяет, является ли фильтр булевым, по эффективному типу поля.
     * Источник типа совпадает с рантаймом: если у фильтра задан typeSpec —
     * атрибут модели не ищется, тип берётся из TypeSpec; иначе — из атрибута.
     *
     * @param Model[] $models
     */
    private function isBoolFilter(
        FilterRule $filter,
        array $models,
        string $modelType,
    ): bool {
        if ($filter->typeSpec instanceof TypeSpec) {
            return $filter->typeSpec->kind === ValueKind::Bool;
        }

        $modelsByType = [];
        foreach ($models as $model) {
            $modelsByType[$model->type] = $model;
        }

        $model = $modelsByType[$modelType] ?? null;
        if (!$model instanceof Model) {
            return false;
        }

        $attribute = $this->resolveAttributeByPath(
            $modelsByType,
            $model,
            $filter->path,
        );
        if (!$attribute instanceof Attribute) {
            return false;
        }

        $type = strtolower($attribute->type);

        return $type === "bool" || $type === "boolean";
    }

    /**
     * Semantics of list action rules. The framework does not apply these rules —
     * `PaginationRule`, `SortRule.defaultDirection/defaultValue/required`,
     * `FilterRule.defaultValue` are available to the consumer via `Config` (DTO
     * generator, repository). The only thing the lib guarantees is their consistency:
     *
     * - pagination: `maxSize` > 0 or null, `defaultSize` > 0, `defaultSize <= maxSize`;
     * - sort: the path resolves to an attribute (through a chain of relations), paths are unique,
     *   `defaultValue.path` resolves, `defaultDirection` and `defaultValue`
     *   are mutually exclusive, `required` and `defaultValue` are mutually exclusive;
     * - filters: paths are unique, `required` and `defaultValue` are mutually exclusive,
     *   `defaultValue.operator` is in `operators`, the value's shape
     *   matches the operator (`in` — a list, `range` — a list of two, others —
     *   a scalar) and it is cast to the field type if the type is known (`typeSpec` or
     *   a model attribute).
     *
     * Семантика правил list-действия. Фреймворк эти правила не применяет —
     * `PaginationRule`, `SortRule.defaultDirection/defaultValue/required`,
     * `FilterRule.defaultValue` доступны потребителю через `Config` (генератор
     * DTO, репозиторий). Единственное, что гарантирует либа — их согласованность:
     *
     * - pagination: `maxSize` > 0 или null, `defaultSize` > 0, `defaultSize <= maxSize`;
     * - sort: путь резолвится в атрибут (через цепочку связей), пути уникальны,
     *   `defaultValue.path` резолвится, `defaultDirection` и `defaultValue`
     *   взаимоисключающи, `required` и `defaultValue` взаимоисключающи;
     * - filters: пути уникальны, `required` и `defaultValue` взаимоисключающи,
     *   `defaultValue.operator` входит в `operators`, значение по форме
     *   соответствует оператору (`in` — список, `range` — список из двух, прочие —
     *   скаляр) и приводится к типу поля, если тип известен (`typeSpec` или
     *   атрибут модели).
     *
     * @param Model[] $models
     * @param Action[] $actions
     * @throws InvalidConfigStructureException
     */
    public function validateListRules(array $models, array $actions): void
    {
        $modelsByType = [];
        foreach ($models as $model) {
            $modelsByType[$model->type] = $model;
        }

        foreach ($actions as $action) {
            if (
                $action->type !== ActionType::List ||
                !$action->config instanceof ListActionConfig
            ) {
                continue;
            }

            $model = $modelsByType[$action->model] ?? null;
            if (!$model instanceof Model) {
                // the existence of the model is checked earlier
                //
                // существование модели проверяется раньше
                continue;
            }

            $prefix = sprintf("schema.actions[%s][list].config", $action->model);

            $this->validatePaginationRule($action->config, $prefix);
            $this->validateSortRules($action->config, $model, $modelsByType, $prefix);
            $this->validateFilterDefaults($action->config, $model, $modelsByType, $prefix);
        }
    }

    private function validatePaginationRule(ListActionConfig $config, string $prefix): void
    {
        $pagination = $config->pagination;
        if (!$pagination instanceof PaginationRule) {
            return;
        }

        if ($pagination->maxSize !== null && $pagination->maxSize < 1) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s.pagination.maxSize' must be a positive integer or null", $prefix),
            );
        }

        if ($pagination->defaultSize < 1) {
            throw new InvalidConfigStructureException(
                sprintf("Field '%s.pagination.defaultSize' must be a positive integer", $prefix),
            );
        }

        if ($pagination->maxSize !== null && $pagination->defaultSize > $pagination->maxSize) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "Field '%s.pagination.defaultSize' (%d) must not exceed maxSize (%d)",
                    $prefix,
                    $pagination->defaultSize,
                    $pagination->maxSize,
                ),
            );
        }
    }

    /**
     * @param array<string, Model> $modelsByType
     */
    private function validateSortRules(
        ListActionConfig $config,
        Model $model,
        array $modelsByType,
        string $prefix,
    ): void {
        $seen = [];
        foreach ($config->sort as $index => $rule) {
            $rulePath = sprintf("%s.sort[%d]", $prefix, $index);

            if (isset($seen[$rule->path])) {
                throw new InvalidConfigStructureException(
                    sprintf("Duplicate sort rule for path '%s' in %s", $rule->path, $rulePath),
                );
            }

            $seen[$rule->path] = true;

            if (!$this->filterPathResolves($modelsByType, $model, $rule->path)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Sort path '%s' in %s does not resolve to an attribute of model '%s'",
                        $rule->path,
                        $rulePath,
                        $model->type,
                    ),
                );
            }

            if ($rule->required && $rule->defaultValue !== null) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Sort rule '%s' in %s cannot be both required and have a defaultValue",
                        $rule->path,
                        $rulePath,
                    ),
                );
            }

            if ($rule->defaultDirection !== null && $rule->defaultValue !== null) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Sort rule '%s' in %s must use either defaultDirection or defaultValue, not both",
                        $rule->path,
                        $rulePath,
                    ),
                );
            }

            if (
                $rule->defaultValue !== null &&
                !$this->filterPathResolves($modelsByType, $model, $rule->defaultValue->path)
            ) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Sort defaultValue path '%s' in %s.defaultValue does not resolve to an attribute of model '%s'",
                        $rule->defaultValue->path,
                        $rulePath,
                        $model->type,
                    ),
                );
            }
        }
    }

    /**
     * @param array<string, Model> $modelsByType
     */
    private function validateFilterDefaults(
        ListActionConfig $config,
        Model $model,
        array $modelsByType,
        string $prefix,
    ): void {
        $seen = [];
        foreach ($config->filters as $index => $rule) {
            $rulePath = sprintf("%s.filters[%d]", $prefix, $index);

            if (isset($seen[$rule->path])) {
                throw new InvalidConfigStructureException(
                    sprintf("Duplicate filter rule for path '%s' in %s", $rule->path, $rulePath),
                );
            }

            $seen[$rule->path] = true;

            if ($rule->defaultValue === null) {
                continue;
            }

            if ($rule->required) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Filter rule '%s' in %s cannot be both required and have a defaultValue",
                        $rule->path,
                        $rulePath,
                    ),
                );
            }

            $operator = $rule->defaultValue->operator;
            $allowed = array_map(
                static fn (FilterOperator|FilterOperatorConfig $item): FilterOperator =>
                    $item instanceof FilterOperatorConfig ? $item->operator : $item,
                $rule->operators,
            );
            if (!in_array($operator, $allowed, true)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Filter defaultValue operator '%s' in %s.defaultValue is not among the rule operators (%s)",
                        $operator->value,
                        $rulePath,
                        implode(", ", array_map(static fn (FilterOperator $o): string => $o->value, $allowed)),
                    ),
                );
            }

            $value = $rule->defaultValue->value;
            $expectsList = $operator === FilterOperator::In || $operator === FilterOperator::Range;

            if ($expectsList && (!is_array($value) || !array_is_list($value))) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Filter defaultValue for operator '%s' in %s.defaultValue must be a list",
                        $operator->value,
                        $rulePath,
                    ),
                );
            }

            if ($operator === FilterOperator::Range && count($value) !== 2) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Filter defaultValue for operator 'range' in %s.defaultValue must be a list of exactly two " .
                        "items [from, to]",
                        $rulePath,
                    ),
                );
            }

            if (!$expectsList && is_array($value)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Filter defaultValue for operator '%s' in %s.defaultValue must be a scalar",
                        $operator->value,
                        $rulePath,
                    ),
                );
            }

            // The type is known: an explicit typeSpec or a model attribute by path
            //
            // Тип известен: явный typeSpec либо атрибут модели по пути
            $typeSpec = $rule->typeSpec;
            if ($typeSpec === null) {
                $attribute = $this->resolveAttributeByPath($modelsByType, $model, $rule->path);
                if ($attribute instanceof Attribute) {
                    $typeSpec = (new TypeSpecParser())->parse($attribute->type);
                }
            }

            if ($typeSpec === null) {
                continue;
            }

            // One list element is a scalar of the same type (nullable/isList removed)
            //
            // Один элемент списка — скаляр того же типа (nullable/isList сняты)
            $scalarSpec = new TypeSpec(
                $typeSpec->kind,
                phpClass: $typeSpec->phpClass,
                declaredName: $typeSpec->declaredName,
            );
            $caster = new Caster();
            foreach ($expectsList ? $value : [$value] as $item) {
                try {
                    $caster->cast($item, $scalarSpec);
                } catch (InvalidArgumentException $invalidArgumentException) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Filter defaultValue in %s.defaultValue does not match the field type: %s",
                            $rulePath,
                            $invalidArgumentException->getMessage(),
                        ),
                        $invalidArgumentException,
                    );
                }
            }
        }
    }

    /**
     * Updating collections (`*ToMany`) — PATCH: a key in the body replaces the collection
     * entirely, which requires a setter. A relation with `setter: false` in `writable`
     * of an update action is a config error: `add` alone will not perform a replacement.
     * `setter: null` (auto-detection by property) cannot be checked at bootstrap —
     * for it the same invariant is ensured by MappingToObject at runtime
     * (CollectionReplaceRequiresSetterException).
     *
     * Update коллекций (`*ToMany`) — PATCH: ключ в теле заменяет коллекцию
     * целиком, для этого нужен setter. Связь с `setter: false` в `writable`
     * update-действия — ошибка конфига: один `add` замену не выполнит.
     * `setter: null` (автоопределение по свойству) на bootstrap не проверить —
     * для него тот же инвариант обеспечивает MappingToObject в рантайме
     * (CollectionReplaceRequiresSetterException).
     *
     * @param Model[] $models
     * @param Action[] $actions
     * @throws InvalidConfigStructureException
     */
    public function validateUpdateCollectionSetters(array $models, array $actions): void
    {
        $modelsByType = [];
        foreach ($models as $model) {
            $modelsByType[$model->type] = $model;
        }

        foreach ($actions as $action) {
            if ($action->type !== ActionType::Update || $action->writable === null) {
                continue;
            }

            $model = $modelsByType[$action->model] ?? null;
            if (!$model instanceof Model) {
                continue;
            }

            foreach (array_keys($action->writable) as $path) {
                $relation = $this->resolveRelationByPath($modelsByType, $model, $path);
                if (!$relation instanceof Relation) {
                    // the path does not resolve — the area of other checks
                    //
                    // путь не резолвится — зона других проверок
                    continue;
                }

                $isCollection = in_array(
                    $relation->type,
                    [Relation::ONE_TO_MANY, Relation::MANY_TO_MANY],
                    true,
                );

                if ($isCollection && $relation->setter === false) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Collection relation '%s' is writable for update on model '%s' but has 'setter: false': " .
                            "update replaces the whole collection, declare a setter or remove the path from 'writable'",
                            $path,
                            $action->model,
                        ),
                    );
                }
            }
        }
    }

    /**
     * The relation by path (all segments are relations) or null.
     *
     * Связь по пути (все сегменты — связи) либо null.
     *
     * @param array<string, Model> $modelsByType
     */
    private function resolveRelationByPath(
        array $modelsByType,
        Model $model,
        string $path,
    ): ?Relation {
        $currentModel = $model;
        $relation = null;

        foreach (explode(".", $path) as $segment) {
            $relation = null;
            foreach ($currentModel->relations as $candidate) {
                if ($candidate->name === $segment) {
                    $relation = $candidate;
                    break;
                }
            }

            if (!$relation instanceof Relation) {
                return null;
            }

            $currentModel = $modelsByType[$relation->targetModel] ?? null;
            if (!$currentModel instanceof Model) {
                return null;
            }
        }

        return $relation;
    }

    /**
     * Checks writable paths: each nested path (`items.product`) requires
     * an explicit declaration of all parents (`items`), because the write mode is not
     * inherited, and an intermediate node without a mode makes no sense.
     *
     * Проверяет пути writable: каждый вложенный путь (`items.product`) требует
     * явного объявления всех родителей (`items`), потому что режим записи не
     * наследуется, а промежуточный узел без режима не имеет смысла.
     *
     * @param Action[] $actions
     * @throws InvalidConfigStructureException
     */
    public function validateWritablePaths(array $actions): void
    {
        foreach ($actions as $action) {
            if ($action->writable === null || $action->writable === []) {
                continue;
            }

            foreach (array_keys($action->writable) as $path) {
                $segments = explode(".", $path);
                $counter = count($segments);

                for ($depth = 1; $depth < $counter; $depth++) {
                    $parent = implode(".", array_slice($segments, 0, $depth));
                    if (!array_key_exists($parent, $action->writable)) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "Writable path '%s' of action '%s' on model '%s' requires its parent '%s' to be " .
                                "declared in 'writable' too",
                                $path,
                                $action->type->value,
                                $action->model,
                                $parent,
                            ),
                        );
                    }
                }
            }
        }
    }

    /**
     * Checks the consistency of locators and actions.
     *
     * Проверяет согласованность локаторов и действий.
     *
     * @param Model[] $models
     * @param Action[] $actions
     * @param array<string, class-string> $locators
     * @throws InvalidConfigStructureException
     */
    public function validateLocators(
        array $models,
        array $actions,
        array $locators,
        ?string $defaultLocator = null,
    ): void {
        $usedLocators = $this->collectUsedAliases(
            $actions,
            $locators,
            "entityLocator",
            "locator",
            static fn (Action $action): ?string => $action->entityLocator,
        );

        // Model and default locators: the alias is declared in impl.locators
        //
        // Локаторы модели и по умолчанию: алиас объявлен в impl.locators
        $declared = [];
        foreach ($models as $model) {
            if ($model->entityLocator !== null) {
                $declared[sprintf("impl.models[%s].entityLocator", $model->type)] = $model->entityLocator;
            }
        }

        if ($defaultLocator !== null) {
            $declared["impl.defaultLocator"] = $defaultLocator;
        }

        foreach ($declared as $path => $alias) {
            if (!array_key_exists($alias, $locators)) {
                throw new InvalidConfigStructureException(
                    sprintf("Field '%s' references undefined locator '%s'", $path, $alias),
                );
            }

            $usedLocators[$alias] = true;
        }

        foreach ($models as $model) {
            foreach ($model->relations as $relation) {
                $found = false;
                foreach ($models as $m) {
                    if ($m->type === $relation->targetModel) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "Relation '%s' in model '%s' refers to unknown target model '%s'",
                            $relation->name,
                            $model->type,
                            $relation->targetModel,
                        ),
                    );
                }
            }
        }

        $this->assertPersistenceDictionary(
            $locators,
            $usedLocators,
            "Locator",
            EntityLocatorInterface::class,
        );
    }

    /**
     * Checks the consistency of savers and actions.
     *
     * Проверяет согласованность сейверов и действий.
     *
     * @param Action[] $actions
     * @param array<string, class-string> $savers
     * @throws InvalidConfigStructureException
     */
    public function validateSavers(array $actions, array $savers): void
    {
        $usedSavers = $this->collectUsedAliases(
            $actions,
            $savers,
            "entitySaver",
            "saver",
            static fn (Action $action): ?string => $action->entitySaver,
        );

        $this->assertPersistenceDictionary(
            $savers,
            $usedSavers,
            "Saver",
            EntitySaverInterface::class,
        );
    }

    /**
     * Checks the consistency of removers and actions.
     *
     * Проверяет согласованность ремуверов и действий.
     *
     * @param Action[] $actions
     * @param array<string, class-string> $removers
     * @throws InvalidConfigStructureException
     */
    public function validateRemovers(array $actions, array $removers): void
    {
        $usedRemovers = $this->collectUsedAliases(
            $actions,
            $removers,
            "entityRemover",
            "remover",
            static fn (Action $action): ?string => $action->entityRemover,
        );

        $this->assertPersistenceDictionary(
            $removers,
            $usedRemovers,
            "Remover",
            EntityRemoverInterface::class,
        );
    }

    /**
     * Locator/saver/remover aliases referenced by actions; each
     * must be non-empty and declared in the dictionary.
     *
     * Алиасы locator/saver/remover, на которые ссылаются действия; каждый
     * обязан быть непустым и объявленным в словаре.
     *
     * @param Action[] $actions
     * @param array<mixed> $dict
     * @param callable(Action): ?string $aliasOf
     * @return array<string, true>
     * @throws InvalidConfigStructureException
     */
    private function collectUsedAliases(
        array $actions,
        array $dict,
        string $field,
        string $kind,
        callable $aliasOf,
    ): array {
        $used = [];

        foreach ($actions as $action) {
            $alias = $aliasOf($action);
            if ($alias === null) {
                continue;
            }

            if (trim($alias) === "") {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Invalid '%s' for action '%s' on model '%s': must be a non-empty string",
                        $field,
                        $action->type->value,
                        $action->model,
                    ),
                );
            }

            if (!array_key_exists($alias, $dict)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "Action '%s' on model '%s' references undefined %s '%s'",
                        $action->type->value,
                        $action->model,
                        $kind,
                        $alias,
                    ),
                );
            }

            $used[$alias] = true;
        }

        return $used;
    }

    /**
     * Each declared alias is used by an action, its class exists
     * and implements the interface.
     *
     * Каждый объявленный алиас используется действием, его класс существует
     * и реализует интерфейс.
     *
     * @param array<mixed> $dict
     * @param array<string, true> $used
     * @param class-string $interface
     * @throws InvalidConfigStructureException
     */
    private function assertPersistenceDictionary(
        array $dict,
        array $used,
        string $kind,
        string $interface,
    ): void {
        foreach (array_keys($dict) as $alias) {
            if (!isset($used[$alias])) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "%s '%s' is declared but not used by any action",
                        $kind,
                        $alias,
                    ),
                );
            }
        }

        $this->assertDictionaryClasses($dict, $kind, $interface);
    }

    /**
     * Checks the correctness of attribute mutator declarations.
     *
     * Проверяет корректность объявления мутаторов атрибутов.
     *
     * @param Model[] $models
     * @param array<string|int, class-string|mixed> $mutators
     * @throws InvalidConfigStructureException
     */
    public function validateMutators(array $models, array $mutators): void
    {
        $this->validateMemberMutators(
            $models,
            $mutators,
            memberKind: "Attribute",
            aliasKind: "mutator",
            interface: AttributeMutatorInterface::class,
            members: static fn (Model $model): array => $model->attributes,
            // attribute: a setter is needed (explicit or auto-detected) or a mutatorSetter
            //
            // атрибут: нужен setter (явный или автоопределение) либо mutatorSetter
            canWrite: static fn (Attribute|Relation $member): bool =>
                ($member->setter !== null && $member->setter !== false) ||
                ($member->mutatorSetter !== null && $member->mutatorSetter !== false),
            noWriteMessage: "%s '%s' in model '%s' uses mutators but has no valid setter or mutatorSetter",
        );
    }

    /**
     * Checks the correctness of relation mutator declarations.
     *
     * Проверяет корректность объявления мутаторов связей.
     *
     * @param Model[] $models
     * @param array<string|int, class-string|mixed> $relationMutators
     * @throws InvalidConfigStructureException
     */
    public function validateRelationMutators(
        array $models,
        array $relationMutators,
    ): void {
        $this->validateMemberMutators(
            $models,
            $relationMutators,
            memberKind: "Relation",
            aliasKind: "relation mutator",
            interface: RelationMutatorInterface::class,
            members: static fn (Model $model): array => $model->relations,
            // relation: the setter may be auto-detected (null); only an explicit false without mutatorSetter
            // is forbidden
            //
            // связь: setter может автоопределяться (null); запрещён только явный false без mutatorSetter
            canWrite: static fn (Attribute|Relation $member): bool =>
                $member->setter !== false ||
                ($member->mutatorSetter !== null && $member->mutatorSetter !== false),
            noWriteMessage: "%s '%s' in model '%s' uses mutators but has setter explicitly disabled " .
                "and no mutatorSetter",
        );
    }

    /**
     * A common algorithm for attribute and relation mutators: the dictionary (aliases and
     * classes with the interface), then each model member with mutators — where
     * to write (canWrite), not constructorParam, all aliases are declared.
     *
     * Общий алгоритм для мутаторов атрибутов и связей: словарь (алиасы и
     * классы с интерфейсом), затем каждый член модели с мутаторами — куда
     * писать (canWrite), не constructorParam, все алиасы объявлены.
     *
     * @param Model[] $models
     * @param array<mixed> $dict
     * @param class-string $interface
     * @param Closure(Model): array<Attribute|Relation> $members
     * @param Closure(Attribute|Relation): bool $canWrite
     * @throws InvalidConfigStructureException
     */
    private function validateMemberMutators(
        array $models,
        array $dict,
        string $memberKind,
        string $aliasKind,
        string $interface,
        Closure $members,
        Closure $canWrite,
        string $noWriteMessage,
    ): void {
        $this->assertMutatorDictionary($dict, ucfirst($aliasKind), $interface);

        foreach ($models as $model) {
            foreach ($members($model) as $member) {
                if (!$this->declaresMutators($member, $memberKind, $model)) {
                    continue;
                }

                if (!$canWrite($member)) {
                    throw new InvalidConfigStructureException(
                        sprintf($noWriteMessage, $memberKind, $member->name, $model->type),
                    );
                }

                $this->assertMutatorUsage($member, $memberKind, $model, $dict, $aliasKind);
            }
        }
    }

    /**
     * Keys of the mutator dictionary are non-empty strings, values are classes with the required
     * interface.
     *
     * Ключи словаря мутаторов — непустые строки, значения — классы с нужным
     * интерфейсом.
     *
     * @param array<mixed> $dict
     * @param class-string $interface
     * @throws InvalidConfigStructureException
     */
    private function assertMutatorDictionary(
        array $dict,
        string $kind,
        string $interface,
    ): void {
        foreach (array_keys($dict) as $alias) {
            if (!is_string($alias) || trim($alias) === "") {
                throw new InvalidConfigStructureException(
                    sprintf("%s alias must be a non-empty string", $kind),
                );
            }
        }

        $this->assertDictionaryClasses($dict, $kind, $interface);
    }

    /**
     * Whether the attribute/relation has mutators; mutatorSetter without mutators is an error.
     *
     * Есть ли у атрибута/связи мутаторы; mutatorSetter без мутаторов — ошибка.
     *
     * @throws InvalidConfigStructureException
     */
    private function declaresMutators(
        Attribute|Relation $member,
        string $memberKind,
        Model $model,
    ): bool {
        if ($member->mutators instanceof Mutators && !$member->mutators->isEmpty()) {
            return true;
        }

        if ($member->mutatorSetter !== null && $member->mutatorSetter !== false) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "%s '%s' in model '%s' declares mutatorSetter but has no mutators",
                    $memberKind,
                    $member->name,
                    $model->type,
                ),
            );
        }

        return false;
    }

    /**
     * Mutators are incompatible with constructorParam; each alias is declared in the dictionary.
     *
     * Мутаторы несовместимы с constructorParam; каждый алиас объявлен в словаре.
     *
     * @param array<mixed> $dict
     * @throws InvalidConfigStructureException
     */
    private function assertMutatorUsage(
        Attribute|Relation $member,
        string $memberKind,
        Model $model,
        array $dict,
        string $aliasKind,
    ): void {
        if ($member->constructorParam) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "%s '%s' in model '%s' uses mutators but is defined as constructorParam",
                    $memberKind,
                    $member->name,
                    $model->type,
                ),
            );
        }

        if (!$member->mutators instanceof Mutators) {
            return;
        }

        foreach ($member->mutators->aliases() as $alias) {
            if (!array_key_exists($alias, $dict)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "%s '%s' in model '%s' references undefined %s alias '%s'",
                        $memberKind,
                        $member->name,
                        $model->type,
                        $aliasKind,
                        $alias,
                    ),
                );
            }
        }
    }

    /**
     * Checks that userModel exists among the models and that the class implements the interface.
     *
     * Проверяет, что userModel существует среди моделей и что класс реализует интерфейс.
     *
     * @param Model[] $models
     * @throws InvalidConfigStructureException
     */
    public function validateUserModel(array $models, string $userModel): void
    {
        foreach ($models as $model) {
            if ($model->type === $userModel) {
                $implements = class_implements($model->class);

                if (
                    $implements === false ||
                    !in_array(UserInterface::class, $implements, true)
                ) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "User class '%s' must implement UserInterface",
                            $model->class,
                        ),
                    );
                }

                return;
            }
        }

        throw new InvalidConfigStructureException(
            sprintf(
                "UserModel '%s' does not exist in the models list",
                $userModel,
            ),
        );
    }

    /**
     * Checks that the repository class exists and implements EntityCollectionInterface.
     *
     * Проверяет, что класс repository существует и имплементирует EntityCollectionInterface.
     *
     * @throws InvalidConfigStructureException
     */
    public function validateRepository(string $repository, string $path): void
    {
        if (!class_exists($repository)) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "Repository class '%s' specified in '%s' does not exist",
                    $repository,
                    $path,
                ),
            );
        }

        $implements = class_implements($repository);

        if (
            $implements === false ||
            !in_array(EntityCollectionInterface::class, $implements, true)
        ) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "Repository class '%s' specified in '%s' must implement EntityCollectionInterface",
                    $repository,
                    $path,
                ),
            );
        }
    }

    /**
     * Checks that all impl entries have corresponding entries in schema.
     * Covers: models, attributes inside models, relations inside models, actions.
     *
     * Проверяет, что все записи impl имеют соответствующие записи в schema.
     * Охватывает: models, attributes внутри моделей, relations внутри моделей, actions.
     *
     * @throws InvalidConfigStructureException
     */
    public function validateNoOrphanedImpl(
        SchemaConfig $schema,
        ImplConfig $impl,
    ): void {
        $schemaModelTypes = [];
        foreach ($schema->models as $schemaModel) {
            $schemaModelTypes[$schemaModel->type] = true;

            $schemaAttrNames = [];
            foreach ($schemaModel->attributes as $attr) {
                $schemaAttrNames[$attr->name] = true;
            }

            $schemaRelNames = [];
            foreach ($schemaModel->relations as $rel) {
                $schemaRelNames[$rel->name] = true;
            }

            foreach ($impl->models as $implModel) {
                if ($implModel->type !== $schemaModel->type) {
                    continue;
                }

                foreach ($implModel->attributes as $implAttr) {
                    if (!isset($schemaAttrNames[$implAttr->name])) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "impl.models[%s].attributes[%s] has no corresponding attribute in schema",
                                $schemaModel->type,
                                $implAttr->name,
                            ),
                        );
                    }
                }

                foreach ($implModel->relations as $implRel) {
                    if (!isset($schemaRelNames[$implRel->name])) {
                        throw new InvalidConfigStructureException(
                            sprintf(
                                "impl.models[%s].relations[%s] has no corresponding relation in schema",
                                $schemaModel->type,
                                $implRel->name,
                            ),
                        );
                    }
                }
            }
        }

        foreach ($impl->models as $implModel) {
            if (!isset($schemaModelTypes[$implModel->type])) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "impl.models[%s] has no corresponding model in schema",
                        $implModel->type,
                    ),
                );
            }
        }

        $schemaActionKeys = [];
        foreach ($schema->actions as $schemaAction) {
            $schemaActionKeys[
                $schemaAction->model . "." . $schemaAction->type->value
            ] = true;
        }

        foreach ($impl->actions as $implAction) {
            $key = $implAction->model . "." . $implAction->type->value;
            if (!isset($schemaActionKeys[$key])) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "impl.actions[%s][%s] has no corresponding action in schema",
                        $implAction->model,
                        $implAction->type->value,
                    ),
                );
            }
        }
    }

    /**
     * Checks that each model/attribute/relation from schema has a corresponding entry in impl.
     * Used to forbid an incomplete impl (Option B): merge will never get null
     * for a model that exists in schema.
     *
     * Проверяет, что каждая модель/атрибут/связь из schema имеет соответствующую запись в impl.
     * Используется для запрета неполного impl (Вариант B): merge никогда не получит null
     * для существующей в schema модели.
     *
     * @throws InvalidConfigStructureException
     */
    public function validateNoMissingImpl(
        SchemaConfig $schema,
        ImplConfig $impl,
    ): void {
        $implModelsByType = [];
        foreach ($impl->models as $implModel) {
            $implModelsByType[$implModel->type] = $implModel;
        }

        foreach ($schema->models as $schemaModel) {
            $implModel = $implModelsByType[$schemaModel->type] ?? null;

            if ($implModel === null) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "schema.models[%s] has no corresponding model in impl",
                        $schemaModel->type,
                    ),
                );
            }

            $implAttrNames = [];
            foreach ($implModel->attributes as $implAttr) {
                $implAttrNames[$implAttr->name] = true;
            }

            foreach ($schemaModel->attributes as $schemaAttr) {
                if (!isset($implAttrNames[$schemaAttr->name])) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "schema.models[%s].attributes[%s] has no corresponding attribute in impl",
                            $schemaModel->type,
                            $schemaAttr->name,
                        ),
                    );
                }
            }

            $implRelNames = [];
            foreach ($implModel->relations as $implRel) {
                $implRelNames[$implRel->name] = true;
            }

            foreach ($schemaModel->relations as $schemaRel) {
                if (!isset($implRelNames[$schemaRel->name])) {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "schema.models[%s].relations[%s] has no corresponding relation in impl",
                            $schemaModel->type,
                            $schemaRel->name,
                        ),
                    );
                }
            }
        }
    }

    /**
     * Checks that all declared mutators are used in at least one model.
     *
     * Проверяет, что все задекларированные мутаторы используются хотя бы в одной модели.
     *
     * @param Model[] $models
     * @param array<string, class-string> $mutators
     * @throws InvalidConfigStructureException
     */
    public function validateNoOrphanedMutators(
        array $models,
        array $mutators,
    ): void {
        $this->assertNoOrphanedAliases(
            $models,
            $mutators,
            static fn (Model $model): array => $model->attributes,
            "Mutator '%s' is declared but not used by any attribute in any model",
        );
    }

    /**
     * Checks that all declared relation mutators are used in at least one model.
     *
     * Проверяет, что все задекларированные мутаторы связей используются хотя бы в одной модели.
     *
     * @param Model[] $models
     * @param array<string, class-string> $relationMutators
     * @throws InvalidConfigStructureException
     */
    public function validateNoOrphanedRelationMutators(
        array $models,
        array $relationMutators,
    ): void {
        $this->assertNoOrphanedAliases(
            $models,
            $relationMutators,
            static fn (Model $model): array => $model->relations,
            "Relation mutator '%s' is declared but not used by any relation in any model",
        );
    }

    /**
     * @param Model[] $models
     * @param array<string, class-string> $dict
     * @param Closure(Model): array<Attribute|Relation> $members
     * @throws InvalidConfigStructureException
     */
    private function assertNoOrphanedAliases(
        array $models,
        array $dict,
        Closure $members,
        string $message,
    ): void {
        $usedAliases = [];
        foreach ($models as $model) {
            foreach ($members($model) as $member) {
                if (!$member->mutators instanceof Mutators) {
                    continue;
                }

                foreach ($member->mutators->aliases() as $alias) {
                    $usedAliases[$alias] = true;
                }
            }
        }

        foreach (array_keys($dict) as $alias) {
            if (!isset($usedAliases[$alias])) {
                throw new InvalidConfigStructureException(sprintf($message, $alias));
            }
        }
    }

    /**
     * Checks the structure of the raw dictionaries locators/savers/removers/mutators/relationMutators:
     * they must be associative arrays with non-empty string keys and non-empty string values.
     *
     * Проверяет структуру raw-словарей locators/savers/removers/mutators/relationMutators:
     * должны быть ассоциативными массивами со строковыми непустыми ключами и строковыми непустыми значениями.
     *
     * @throws InvalidConfigStructureException
     */
    public function validateImplDictionaries(ImplConfig $impl): void
    {
        $dicts = [
            "locators" => $impl->locators,
            "savers" => $impl->savers,
            "removers" => $impl->removers,
            "mutators" => $impl->mutators,
            "relationMutators" => $impl->relationMutators,
        ];

        foreach ($dicts as $dictName => $dict) {
            if ($dict !== [] && array_is_list($dict)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "impl.%s must be an associative array, not a list",
                        $dictName,
                    ),
                );
            }

            foreach ($dict as $key => $value) {
                if (!is_string($key) || trim($key) === "") {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "impl.%s key must be a non-empty string",
                            $dictName,
                        ),
                    );
                }

                if (!is_string($value) || trim($value) === "") {
                    throw new InvalidConfigStructureException(
                        sprintf(
                            "impl.%s[%s] value must be a non-empty string class name",
                            $dictName,
                            $key,
                        ),
                    );
                }
            }
        }
    }

    /**
     * Checks the consistency of SchemaWriteMode and impl attributes before merge.
     * ReadOnly: impl must not set setter or constructorParam=true.
     * CreateOnly: impl must not set setter; constructorParam must be true or not set.
     * Write: impl must not set constructorParam=true (write is incompatible with constructor-only).
     *
     * Проверяет согласованность SchemaWriteMode и impl-атрибутов до merge.
     * ReadOnly: impl не должен задавать setter или constructorParam=true.
     * CreateOnly: impl не должен задавать setter; constructorParam должен быть true или не задан.
     * Write: impl не должен задавать constructorParam=true (write несовместим с constructor-only).
     *
     * @throws InvalidConfigStructureException
     */
    public function validateSchemaImplConsistency(
        SchemaConfig $schema,
        ImplConfig $impl,
    ): void {
        $implAttrsByModel = [];
        foreach ($impl->models as $implModel) {
            $byName = [];
            foreach ($implModel->attributes as $implAttr) {
                $byName[$implAttr->name] = $implAttr;
            }

            $implAttrsByModel[$implModel->type] = $byName;
        }

        foreach ($schema->models as $schemaModel) {
            $implAttrs = $implAttrsByModel[$schemaModel->type] ?? [];

            foreach ($schemaModel->attributes as $schemaAttr) {
                if ($schemaAttr->writeMode === null) {
                    continue;
                }

                $implAttr = $implAttrs[$schemaAttr->name] ?? null;
                if ($implAttr === null) {
                    continue;
                }

                $path = sprintf(
                    "impl.models[%s].attributes[%s]",
                    $schemaModel->type,
                    $schemaAttr->name,
                );

                match ($schemaAttr->writeMode) {
                    SchemaWriteMode::ReadOnly => $this->assertReadOnly(
                        $implAttr,
                        $path,
                    ),
                    SchemaWriteMode::CreateOnly => $this->assertCreateOnly(
                        $implAttr,
                        $path,
                    ),
                    SchemaWriteMode::Write => $this->assertWrite(
                        $implAttr,
                        $path,
                    ),
                };
            }
        }
    }

    private function assertReadOnly(ImplAttribute $implAttr, string $path): void
    {
        if ($implAttr->setter !== null && $implAttr->setter !== false) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "%s declares a setter but schema writeMode is 'read-only'",
                    $path,
                ),
            );
        }

        if ($implAttr->constructorParam) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "%s declares constructorParam=true but schema writeMode is 'read-only'",
                    $path,
                ),
            );
        }
    }

    private function assertCreateOnly(
        ImplAttribute $implAttr,
        string $path,
    ): void {
        if ($implAttr->setter !== null && $implAttr->setter !== false) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "%s declares a setter but schema writeMode is 'create-only'",
                    $path,
                ),
            );
        }
    }

    private function assertWrite(ImplAttribute $implAttr, string $path): void
    {
        if ($implAttr->constructorParam) {
            throw new InvalidConfigStructureException(
                sprintf(
                    "%s declares constructorParam=true but schema writeMode is 'write' (use 'create-only' instead)",
                    $path,
                ),
            );
        }
    }

    /**
     * Values of impl.locators/savers/removers are existing classes with the required
     * interface (symmetrically to validateMutators).
     *
     * Значения impl.locators/savers/removers — существующие классы с нужным
     * интерфейсом (симметрично validateMutators).
     *
     * @param array<mixed> $dict
     * @param class-string $interface
     * @throws InvalidConfigStructureException
     */
    private function assertDictionaryClasses(
        array $dict,
        string $kind,
        string $interface,
    ): void {
        foreach ($dict as $alias => $class) {
            if (!is_string($class) || !class_exists($class)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "%s '%s' refers to undefined class '%s'",
                        $kind,
                        $alias,
                        is_string($class) ? $class : get_debug_type($class),
                    ),
                );
            }

            if (!is_subclass_of($class, $interface)) {
                throw new InvalidConfigStructureException(
                    sprintf(
                        "%s '%s' class '%s' must implement %s",
                        $kind,
                        $alias,
                        $class,
                        (new ReflectionClass($interface))->getShortName(),
                    ),
                );
            }
        }
    }
}
