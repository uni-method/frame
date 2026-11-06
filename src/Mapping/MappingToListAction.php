<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping;

use InvalidArgumentException;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    ListAction\FilterOperator,
    ListAction\FilterOperatorConfig,
    ListAction\FilterRule,
    ListAction\ListActionConfig,
    ListAction\SortRule,
    Model,
    ModelLocatorResolver,
    Relation,
};
use UniMethod\Frame\Exception\ConfigAlreadySetException;
use UniMethod\Frame\Mapping\ListQuery\Exception\{
    InvalidListQueryException,
    ListActionMisconfiguredException,
    UnknownFilterFieldException,
    UnsatisfiableListFilterException,
};
use UniMethod\Frame\Mapping\ListQuery\{ListDtoClassNames, ParsedFilter};
use UniMethod\Frame\Persistence\{
    EntityBatchLocatorInterface,
    EntityCollectionInterface,
    EntityLocatorContainerInterface,
};
use UniMethod\Frame\Type\{Caster, TypeSpec, TypeSpecParser, ValueKind};

class MappingToListAction
{
    private ?Config $config;

    public function __construct(
        private readonly TypeSpecParser $typeParser,
        private readonly ?EntityLocatorContainerInterface $locators = null,
        private readonly Caster $caster = new Caster(),
        ?Config $config = null,
    ) {
        $this->config = $config;
    }

    /**
     * The config is set once: here or in the constructor.
     *
     * Конфиг задаётся один раз: здесь либо в конструкторе.
     */
    public function setConfig(Config $config): self
    {
        if ($this->config instanceof Config) {
            throw new ConfigAlreadySetException(self::class);
        }

        $this->config = $config;
        return $this;
    }

    /**
     * Query parsing errors are typed: a misconfiguration of a list action →
     * ListActionMisconfiguredException (500); malformed request parameters,
     * filter fields outside config->filters and operators outside the rule's operators →
     * InvalidListQueryException (400). The config is the single source of truth
     * for filters: a field is applied only if the path is declared in filters and
     * the operator is in operators; a mismatch between the config and the generated
     * DTO classes is caught at bootstrap (ConfigValidator::validateListFilters).
     * Add tests to validation
     * Add tests to mapping
     *
     * Ошибки разбора query типизированы: мисконфиг list-действия →
     * ListActionMisconfiguredException (500); кривые параметры запроса,
     * поля фильтра вне config->filters и операторы вне operators правила →
     * InvalidListQueryException (400). Конфиг — единственный источник истины
     * для фильтров: поле применяется только если путь заявлен в filters и
     * оператор входит в operators; рассинхрон конфига и сгенерированных
     * DTO-классов ловится на bootstrap (ConfigValidator::validateListFilters).
     * Добавить тесты в валидацию
     * Добавить тесты в маппинг
     *
     * @param array<string|int, mixed> $rawQuery the result of parse_str($_SERVER['QUERY_STRING'], $params);
     * @param string $modelType the model type for which the request is created
     * @return object|null root class
     */
    public function map(array $rawQuery, string $modelType): ?object
    {
        if (!$this->config instanceof Config) {
            throw new ListActionMisconfiguredException(
                "Config not set. Use setConfig() first.",
            );
        }

        $action = $this->findListAction($modelType);
        if (!$action->config instanceof ListActionConfig) {
            throw new ListActionMisconfiguredException(
                "Please add config for model " . $modelType . " list action",
            );
        }

        $config = $action->config;
        $model = $this->findModel($action->model);

        // config->repository is REQUIRED
        //
        // config->repository ОБЯЗАТЕЛЕН
        if ($config->repository === null) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Config repository must be set for model '%s'",
                    $model->type,
                ),
            );
        }

        // config->repository must implement
        //
        // config->repository должен имплементировать
        if (
            !is_subclass_of(
                $config->repository,
                EntityCollectionInterface::class,
            )
        ) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Repository for model '%s' must implements EntityCollectionInterface",
                    $model->type,
                ),
            );
        }

        if ($config->class === null) {
            return null;
        }

        $rootClassName = $config->class;

        if (!class_exists($rootClassName)) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Root class '%s' not found. Make sure to generate classes first.",
                    $rootClassName,
                ),
            );
        }

        $names = new ListDtoClassNames($rootClassName, $model->class);

        $rootObject = new $rootClassName();

        // Map filters - always create if there is at least one required filter
        //
        // Мапим фильтры - создаём всегда, если есть хотя бы один required фильтр
        $hasRequiredFilter = $this->hasRequiredRule($config->filters);
        $filterClassName = $names->filter();

        if (
            class_exists($filterClassName) &&
            property_exists($rootObject, "filter")
        ) {
            $filterData = $rawQuery["filter"] ?? [];
            // Create filter if: there is data (a non-empty array) OR there is a required one
            //
            // Создаём filter если: есть данные (непустой массив) ИЛИ есть required
            if (
                ($filterData !== [] && is_array($filterData)) ||
                $hasRequiredFilter
            ) {
                $rootObject->filter = $this->mapFilter(
                    $this->parseFilters($rawQuery, $modelType),
                    $this->presentOperators(is_array($filterData) ? $filterData : []),
                    $names,
                    $model,
                    $config->filters,
                );
            }
        }

        // Map sorting — the same standard as for filters: config->sort is
        // a whitelist, everything outside it and an invalid direction → 400
        //
        // Мапим сортировку — тот же стандарт, что у фильтров: config->sort —
        // вайтлист, всё вне него и неверное направление → 400
        $hasRequiredSort = $this->hasRequiredRule($config->sort);
        $sortClassName = $names->sort();
        $sortData = $rawQuery["sort"] ?? [];
        if ($sortData === "") {
            $sortData = [];
        }

        if (!is_array($sortData)) {
            throw new InvalidListQueryException(
                "Parameter 'sort' must be an object: sort[<field>]=asc|desc",
            );
        }

        if ($sortData !== [] && $config->sort === []) {
            throw new InvalidListQueryException(
                sprintf("Sorting is not available for model '%s'", $model->type),
            );
        }

        if ($sortData !== [] || $hasRequiredSort) {
            if (
                !class_exists($sortClassName) ||
                !property_exists($rootObject, "sort")
            ) {
                throw new ListActionMisconfiguredException(
                    sprintf(
                        "Sort rules are configured for model '%s' but DTO %s or property '%s::\$sort' does not exist",
                        $model->type,
                        $sortClassName,
                        $rootClassName,
                    ),
                );
            }

            $rootObject->sort = $this->mapSort(
                $sortData,
                $sortClassName,
                $config->sort,
                $model->type,
            );
        }

        // Map pagination
        //
        // Мапим пагинацию
        $paginationClassName = $names->pagination();
        if (
            class_exists($paginationClassName) &&
            property_exists($rootObject, "pagination")
        ) {
            $pageData = $rawQuery["page"] ?? [];
            $rootObject->pagination = $this->mapPagination(
                is_array($pageData) ? $pageData : [],
                $paginationClassName,
            );
        }

        return $rootObject;
    }

    private function findListAction(string $modelType): Action
    {
        if ($this->config instanceof Config) {
            foreach ($this->config->actions as $action) {
                if (
                    $action->model === $modelType &&
                    $action->type === ActionType::List
                ) {
                    return $action;
                }
            }
        }

        throw new ListActionMisconfiguredException(
            sprintf("List action not found for model '%s'", $modelType),
        );
    }

    private function findModel(string $modelType): Model
    {
        if ($this->config instanceof Config) {
            foreach ($this->config->models as $model) {
                if ($model->type === $modelType) {
                    return $model;
                }
            }
        }

        throw new ListActionMisconfiguredException(
            sprintf("Model '%s' not found in config", $modelType),
        );
    }

    /**
     * Checks whether there is at least one required rule
     *
     * Проверяет есть ли хотя бы одно required правило
     *
     * @param array<FilterRule|SortRule> $rules FilterRule[] or SortRule[]
     */
    private function hasRequiredRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule->required) {
                return true;
            }
        }

        return false;
    }

    /**
     * Operators under which the query has a non-empty array of fields — even if
     * no values remain after parsing (`filter[equal][owner]=[]`): the operator
     * object is created empty, as before.
     *
     * Операторы, под которыми в query есть непустой массив полей — даже если
     * после разбора значений не осталось (`filter[equal][owner]=[]`): объект
     * оператора создаётся пустым, как и раньше.
     *
     * @param array<string|int, mixed> $filterData
     * @return list<FilterOperator>
     */
    private function presentOperators(array $filterData): array
    {
        $operators = [];
        foreach ($filterData as $operator => $fields) {
            if (!is_array($fields) || $fields === []) {
                continue;
            }

            $filterOperator = FilterOperator::tryFrom((string) $operator);
            if ($filterOperator instanceof FilterOperator) {
                $operators[] = $filterOperator;
            }
        }

        return $operators;
    }

    /**
     * Parses `filter[<operator>][<path>]=<value>` from the query through the whitelist
     * `config.filters`: an unknown operator, a path outside the rules or an operator outside
     * the rule's `operators` → 400. Empty nested structures are not considered
     * filtering and are skipped. Values are raw (type casting is done by
     * map()). The only query parsing in the lib: ApiService checks access on operators
     * against this same result.
     *
     * Разбирает `filter[<operator>][<path>]=<value>` из query через вайтлист
     * `config.filters`: неизвестный оператор, путь вне правил или оператор вне
     * `operators` правила → 400. Пустые вложенные структуры не считаются
     * фильтрацией и пропускаются. Значения — сырые (приведение типов делает
     * map()). Единственный разбор query в либе: ApiService проверяет по этому
     * же результату access на операторах.
     *
     * @param array<string|int, mixed> $rawQuery
     * @return list<ParsedFilter>
     * @throws InvalidListQueryException|ListActionMisconfiguredException
     */
    public function parseFilters(array $rawQuery, string $modelType): array
    {
        $action = $this->findListAction($modelType);
        $filterRules = $action->config->filters ?? [];

        $filterData = $rawQuery["filter"] ?? [];
        if (!is_array($filterData)) {
            return [];
        }

        $parsed = [];
        foreach ($filterData as $operator => $fields) {
            if (!is_array($fields) || $fields === []) {
                continue;
            }

            $operatorKey = (string) $operator;
            $filterOperator = FilterOperator::tryFrom($operatorKey);
            if (!$filterOperator instanceof FilterOperator) {
                throw new InvalidListQueryException(
                    sprintf("Unknown filter operator '%s'", $operatorKey),
                );
            }

            foreach (self::flattenNestedArray($fields) as $fieldPath => $value) {
                // An empty nested structure carries no data — it is ignored,
                // this is not a filtering attempt
                //
                // Пустая вложенная структура данных не несёт — игнорируется,
                // это не попытка фильтрации
                if (is_array($value) && $value === []) {
                    continue;
                }

                $fieldPath = (string) $fieldPath;
                $rule = $this->findFilterRule($filterRules, $fieldPath);
                if (!$rule instanceof FilterRule) {
                    throw new InvalidListQueryException(
                        sprintf("Unknown filter field '%s'", $fieldPath),
                    );
                }

                if (!$this->ruleAllowsOperator($rule, $filterOperator)) {
                    throw new InvalidListQueryException(
                        sprintf(
                            "Operator '%s' is not allowed for filter field '%s'",
                            $operatorKey,
                            $fieldPath,
                        ),
                    );
                }

                $parsed[] = new ParsedFilter($filterOperator, $fieldPath, $rule, $value);
            }
        }

        return $parsed;
    }

    /**
     * Maps parsed filters into a {Model}Filter object: one operator DTO per
     * operator, values are cast to the field type (or to the relation's entity).
     *
     * Мапит разобранные фильтры в объект {Model}Filter: по операторному DTO на
     * каждый оператор, значения приводятся к типу поля (или к сущности связи).
     *
     * @param list<ParsedFilter> $parsedFilters
     * @param list<FilterOperator> $presentOperators Operators present in the query
     *        (the operator object is created even without values — an empty structure)
     * @param FilterRule[] $filterRules
     */
    private function mapFilter(
        array $parsedFilters,
        array $presentOperators,
        ListDtoClassNames $names,
        Model $model,
        array $filterRules,
    ): object {
        $filterClassName = $names->filter();
        $filterObject = new $filterClassName();

        /** @var array<string, array{0: FilterOperator, 1: array<string, FilterRule>, 2: array<string, mixed>}> $byOperator */
        $byOperator = [];
        foreach ($presentOperators as $presentOperator) {
            $byOperator[$presentOperator->value] = [$presentOperator, [], []];
        }

        foreach ($parsedFilters as $parsedFilter) {
            $key = $parsedFilter->operator->value;
            $byOperator[$key] ??= [$parsedFilter->operator, [], []];
            $byOperator[$key][1][$parsedFilter->path] = $parsedFilter->rule;
            $byOperator[$key][2][$parsedFilter->path] = $parsedFilter->value;
        }

        foreach ($byOperator as [$filterOperator, $rulesByPath, $valuesByPath]) {
            // Operator class name: TestProductEqual, TestProductIn, TestProductRange, TestProductLike
            //
            // Имя класса оператора: TestProductEqual, TestProductIn, TestProductRange, TestProductLike
            $operatorClassName = $names->operator($filterOperator);
            if (!class_exists($operatorClassName)) {
                // Config↔generated classes mismatch: caught at
                // bootstrap (ConfigValidator::validateListFilters)
                //
                // Рассинхрон конфиг↔сгенерированные классы: ловится на
                // bootstrap (ConfigValidator::validateListFilters)
                continue;
            }

            $operatorObject = new $operatorClassName();

            foreach ($rulesByPath as $fieldPath => $rule) {
                $value = $valuesByPath[$fieldPath];

                // The path may point to a whole relation: the value is the id
                // of the target model, the entity is put into the DTO. The property name
                // is common for all paths (user -> user, a.b -> relARelB)
                //
                // Путь может указывать на связь целиком: значение — id
                // целевой модели, в DTO кладётся сущность. Имя свойства
                // общее для всех путей (user -> user, a.b -> relARelB)
                $relation = $this->tryResolveRelationByPath($model, $fieldPath);

                $propertyName = $this->resolvePropertyName($fieldPath);

                if (!property_exists($operatorObject, $propertyName)) {
                    // The path is declared in the config, but the property is missing from the DTO —
                    // the mismatch is caught at bootstrap
                    //
                    // Путь заявлен в конфиге, но свойства нет в DTO —
                    // рассинхрон ловится на bootstrap
                    continue;
                }

                if ($relation instanceof Relation) {
                    $operatorObject->$propertyName = $this->resolveRelationFilterValue(
                        $relation,
                        $value,
                        $rule,
                        $filterOperator,
                    );
                } else {
                    // Get the typeSpec: from a custom rule or from the model attribute.
                    // The path is declared in filters, so "does not resolve and has no typeSpec" is
                    // a misconfiguration, not a client error.
                    //
                    // Получаем typeSpec: из кастомного правила или из атрибута модели.
                    // Путь заявлен в filters, поэтому «не резолвится и нет typeSpec» —
                    // это мисконфигурация, а не клиентская ошибка.
                    try {
                        $typeSpec =
                            $rule->typeSpec ??
                            $this->typeParser->parse(
                                $this->resolveAttributeByPath($model, $fieldPath)
                                    ->type,
                            );
                    } catch (UnknownFilterFieldException) {
                        throw new ListActionMisconfiguredException(
                            sprintf(
                                "Filter rule '%s' of model '%s' does not resolve to a schema attribute and has no " .
                                "typeSpec",
                                $fieldPath,
                                $model->type,
                            ),
                        );
                    }

                    // Convert the value to the required type depending on the operator
                    //
                    // Конвертируем значение в нужный тип в зависимости от оператора
                    $operatorObject->$propertyName = $this->convertValue(
                        $value,
                        $typeSpec,
                        $filterOperator,
                    );
                }

                if ($operatorObject instanceof TouchableDto) {
                    $operatorObject->markAsSet($propertyName);
                }
            }

            // Set the operator in the filter object
            //
            // Устанавливаем оператор в filter объект
            $filterProperty = lcfirst($filterOperator->name);
            if (property_exists($filterObject, $filterProperty)) {
                $filterObject->$filterProperty = $operatorObject;
            }
        }

        // Handle required filters - if they were not passed, create the operator object
        // and leave all fields null for subsequent validation
        //
        // Обрабатываем required фильтры - если они не были переданы, создаём объект оператора
        // и оставляем все поля null для последующей валидации
        $this->ensureRequiredFilters(
            $filterObject,
            $filterRules,
            $names,
            array_keys($byOperator),
        );

        return $filterObject;
    }

    /**
     * Finds a FilterRule by the field path
     *
     * Ищет FilterRule по пути поля
     *
     * @param array<FilterRule> $filterRules
     */
    private function findFilterRule(
        array $filterRules,
        string $path,
    ): ?FilterRule {
        foreach ($filterRules as $rule) {
            if ($rule->path === $path) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Checks that the operator is in the rule's operators
     *
     * Проверяет, что оператор входит в operators правила
     */
    private function ruleAllowsOperator(
        FilterRule $rule,
        FilterOperator $operator,
    ): bool {
        foreach ($rule->operators as $item) {
            $ruleOperator =
                $item instanceof FilterOperatorConfig ? $item->operator : $item;
            if ($ruleOperator === $operator) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converts a field path into a class property name
     * Local: as-is (name -> name)
     * Relations: relSegment1RelSegment2 (relation.city.name -> relRelationRelCityRelName)
     *
     * Преобразует путь поля в имя свойства класса
     * Локальные: as-is (name -> name)
     * Реляции: relSegment1RelSegment2 (relation.city.name -> relRelationRelCityRelName)
     */
    private function resolvePropertyName(string $fieldPath): string
    {
        if (!str_contains($fieldPath, ".")) {
            return $fieldPath;
        }

        // relation.city.name -> [relation, city, name]
        $segments = explode(".", $fieldPath);

        // [relation, city, name] -> [Relation, City, Name]
        $pascalCased = array_map(
            static fn ($seg): string => ucfirst($seg),
            $segments,
        );

        // rel + Relation + Rel + City + Rel + Name = relRelationRelCityRelName
        return "rel" . implode("Rel", $pascalCased);
    }

    /**
     * Converts a nested array into a flat structure with dot-separated paths
     *
     * Example:
     * ['owner' => ['id' => 1]] => ['owner.id' => 1]
     * ['name' => 'test'] => ['name' => 'test']
     *
     * Преобразует вложенный массив в плоскую структуру с путями через точку
     *
     * Пример:
     * ['owner' => ['id' => 1]] => ['owner.id' => 1]
     * ['name' => 'test'] => ['name' => 'test']
     *
     * @param array<string|int, mixed> $data Input data (may be a nested array)
     * @param string $prefix Current path prefix (used for recursion)
     * @return array<string, mixed> Flat array with dot-separated paths
     */
    private static function flattenNestedArray(array $data, string $prefix = ""): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            // Build the current path
            //
            // Формируем текущий путь
            $path = $prefix !== "" ? $prefix . "." . $key : (string) $key;

            // If the value is a non-empty array, check whether it is a nested structure
            //
            // Если значение - массив и не пустое, проверяем является ли оно вложенной структурой
            if (is_array($value) && $value !== []) {
                // Check whether this is an array of values or a nested structure
                // If all keys are numeric - this is an array of values (for In, Range operators)
                //
                // Проверяем, является ли это массивом значений или вложенной структурой
                // Если все ключи числовые - это массив значений (для In, Range операторов)
                $isValueArray =
                    array_keys($value) === range(0, count($value) - 1);

                if ($isValueArray) {
                    // This is an array of values - keep it as is
                    //
                    // Это массив значений - сохраняем как есть
                    $result[$path] = $value;
                } else {
                    // This is a nested structure - process it recursively
                    //
                    // Это вложенная структура - рекурсивно обрабатываем
                    $result = array_merge(
                        $result,
                        self::flattenNestedArray($value, $path),
                    );
                }
            } else {
                // A scalar value or null - keep it
                //
                // Скалярное значение или null - сохраняем
                $result[$path] = $value;
            }
        }

        return $result;
    }

    /**
     * Resolves an attribute by path (including nested relations)
     *
     * Разрешает атрибут по пути (включая вложенные реляции)
     */
    private function resolveAttributeByPath(
        Model $model,
        string $path,
    ): Attribute {
        if (!str_contains($path, ".")) {
            return $this->findAttribute($model, $path);
        }

        $segments = explode(".", $path);
        $currentModel = $model;

        // Walk through all relations (all segments except the last one)
        //
        // Проходим по всем реляциям (все сегменты кроме последнего)
        for ($i = 0; $i < count($segments) - 1; $i++) {
            $relationName = $segments[$i];
            $relation = $this->findRelation($currentModel, $relationName);
            $currentModel = $this->findModel($relation->targetModel);
        }

        // The last segment is an attribute of the final model
        //
        // Последний сегмент - это атрибут финальной модели
        return $this->findAttribute($currentModel, end($segments));
    }

    /**
     * Resolves a path all of whose segments are relations (a filter by a whole relation).
     * Returns the final relation or null if the path is not one.
     *
     * Резолвит путь, все сегменты которого — связи (фильтр по связи целиком).
     * Возвращает финальную связь либо null, если путь ей не является.
     */
    private function tryResolveRelationByPath(
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

            $currentModel = $this->findModel($relation->targetModel);
        }

        return $relation;
    }

    /**
     * Value of a filter by relation: id → entity of the target model via the locator of its
     * read action. For a list of ids (`in`) — with one findMany() call if the
     * locator implements EntityBatchLocatorInterface, otherwise find() for each id;
     * missing ids from the list are dropped. If nothing is found (or the single
     * id for `equal`) — UnsatisfiableListFilterException: the selection is knowingly empty.
     *
     * Значение фильтра по связи: id → сущность целевой модели через локатор её
     * read-действия. Для списка id (`in`) — одним вызовом findMany(), если
     * локатор реализует EntityBatchLocatorInterface, иначе find() на каждый id;
     * отсутствующие id из списка выпадают. Если не найдено ничего (или единственный
     * id для `equal`) — UnsatisfiableListFilterException: выборка заведомо пуста.
     *
     * @throws UnsatisfiableListFilterException|InvalidListQueryException|ListActionMisconfiguredException
     */
    private function resolveRelationFilterValue(
        Relation $relation,
        mixed $value,
        FilterRule $rule,
        FilterOperator $operator,
    ): mixed {
        $targetModel = $this->findModel($relation->targetModel);

        $typeSpec =
            $rule->typeSpec ??
            $this->typeParser->parse(
                $this->findAttribute($targetModel, "id")->type,
            );

        $ids = $this->convertValue($value, $typeSpec, $operator);

        if (!is_array($ids)) {
            if ($ids === null) {
                return null;
            }

            $found = $this->loadRelationEntities($targetModel, [$ids]);
            if ($found === []) {
                throw new UnsatisfiableListFilterException($targetModel->type, $rule->path, [$ids]);
            }

            return $found[0];
        }

        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => $id !== null)));
        if ($ids === []) {
            return [];
        }

        $found = $this->loadRelationEntities($targetModel, $ids);
        if ($found === []) {
            throw new UnsatisfiableListFilterException($targetModel->type, $rule->path, $ids);
        }

        return $found;
    }

    /**
     * @param list<mixed> $ids
     * @return list<object>
     */
    private function loadRelationEntities(Model $targetModel, array $ids): array
    {
        foreach ($ids as $id) {
            if (!is_int($id) && !is_string($id)) {
                throw new InvalidListQueryException(
                    sprintf(
                        "Filter by relation '%s' requires scalar id value",
                        $targetModel->type,
                    ),
                );
            }
        }

        if (!$this->locators instanceof EntityLocatorContainerInterface) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Filter by relation to model '%s' requires locator container in MappingToListAction",
                    $targetModel->type,
                ),
            );
        }

        $locator = $this->locators->getEntityLocatorByName(
            $this->findModelLocatorName($targetModel),
        );

        /** @var list<int|string> $ids */
        if ($locator instanceof EntityBatchLocatorInterface) {
            return $locator->findMany($targetModel->class, $ids);
        }

        $found = [];
        foreach ($ids as $id) {
            $entity = $locator->find($targetModel->class, $id);
            if ($entity !== null) {
                $found[] = $entity;
            }
        }

        return $found;
    }

    private function findModelLocatorName(Model $targetModel): string
    {
        $locator = $this->config?->locatorForModel($targetModel->type);

        if ($locator === null) {
            throw new ListActionMisconfiguredException(
                sprintf(
                    "Model '%s' has no entity locator required for relation filter. %s",
                    $targetModel->type,
                    ModelLocatorResolver::hint($targetModel->type),
                ),
            );
        }

        return $locator;
    }

    private function findAttribute(Model $model, string $name): Attribute
    {
        foreach ($model->attributes as $attr) {
            if ($attr->name === $name) {
                return $attr;
            }
        }

        throw new UnknownFilterFieldException(
            sprintf(
                "Attribute '%s' not found in model '%s'",
                $name,
                $model->type,
            ),
        );
    }

    private function findRelation(Model $model, string $name): Relation
    {
        foreach ($model->relations as $relation) {
            if ($relation->name === $name) {
                return $relation;
            }
        }

        throw new UnknownFilterFieldException(
            sprintf(
                "Relation '%s' not found in model '%s'",
                $name,
                $model->type,
            ),
        );
    }

    /**
     * Converts the value depending on the type and operator
     *
     * Конвертирует значение в зависимости от типа и оператора
     */
    private function convertValue(
        mixed $value,
        TypeSpec $typeSpec,
        FilterOperator $operator,
    ): mixed {
        // Operators that require an array of values
        //
        // Операторы, требующие массив значений
        $arrayOperators = [FilterOperator::In, FilterOperator::Range];
        $requiresArray = in_array($operator, $arrayOperators, true);

        // If the operator requires an array
        //
        // Если оператор требует массив
        if ($requiresArray) {
            if (!is_array($value)) {
                throw new InvalidListQueryException(
                    sprintf(
                        "Operator %s requires array value",
                        $operator->value,
                    ),
                );
            }

            // Convert each array element to the required type
            //
            // Конвертируем каждый элемент массива в нужный тип
            return array_map(
                fn ($item): mixed => $this->convertScalarValue($item, $typeSpec),
                $value,
            );
        }

        // If the field itself is a list
        //
        // Если поле само по себе список
        if ($typeSpec->isList) {
            if (!is_array($value)) {
                throw new InvalidListQueryException(
                    "List field requires array value",
                );
            }

            return array_map(
                fn ($item): mixed => $this->convertScalarValue($item, $typeSpec),
                $value,
            );
        }

        // A regular scalar value
        //
        // Обычное скалярное значение
        return $this->convertScalarValue($value, $typeSpec);
    }

    private function convertScalarValue(mixed $value, TypeSpec $typeSpec): mixed
    {
        if ($value === null) {
            if (!$typeSpec->nullable) {
                throw new InvalidListQueryException(
                    "Non-nullable field cannot be null",
                );
            }

            return null;
        }

        if ($value === "") {
            if (!$typeSpec->nullable) {
                throw new InvalidListQueryException(
                    "Non-nullable field cannot be null",
                );
            }

            return null;
        }

        // Valid combination to pass exactly empty string, if you need pass '' you should send via ''''
        //
        // Допустимая комбинация для передачи именно пустой строки; если нужно передать '',
        // следует отправлять через ''''
        if (
            is_string($value) &&
            strlen($value) >= 2 &&
            strlen($value) % 2 === 0 &&
            trim($value, "'") === ""
        ) {
            $value = substr($value, 1, -1);
        }

        return $this->castStrict($value, $typeSpec->kind);
    }

    /**
     * The same rules as for the request body (Caster), but the error is 400.
     *
     * Те же правила, что для тела запроса (Caster), но ошибка — 400.
     */
    private function castStrict(mixed $value, ValueKind $kind): mixed
    {
        try {
            return $this->caster->cast($value, new TypeSpec($kind));
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new InvalidListQueryException(
                sprintf(
                    "Invalid filter value for type %s: %s",
                    $kind->value,
                    $invalidArgumentException->getMessage(),
                ),
                $invalidArgumentException,
            );
        }
    }

    /**
     * Creates operator objects for required filters if they were not passed
     *
     * Создаёт объекты операторов для required фильтров, если они не были переданы
     *
     * @param array<FilterRule> $filterRules
     * @param list<string> $providedOperators FilterOperator values already filled from the query
     */
    private function ensureRequiredFilters(
        object $filterObject,
        array $filterRules,
        ListDtoClassNames $names,
        array $providedOperators,
    ): void {
        // Operators that have required rules
        //
        // Операторы, у которых есть required правила
        $requiredOperators = [];
        foreach ($filterRules as $rule) {
            if ($rule->required) {
                foreach ($rule->operators as $operator) {
                    // An operator may be in the extended FilterOperatorConfig form
                    //
                    // Оператор может быть в расширенной форме FilterOperatorConfig
                    $filterOperator =
                        $operator instanceof FilterOperatorConfig
                            ? $operator->operator
                            : $operator;
                    $requiredOperators[$filterOperator->name] = $filterOperator;
                }
            }
        }

        // For each operator with required fields check whether it was passed
        //
        // Для каждого оператора с required полями проверяем, был ли он передан
        foreach ($requiredOperators as $operatorName => $filterOperator) {
            $operatorLower = strtolower($operatorName);
            $filterProperty = lcfirst($operatorName);

            // If the operator was already processed above, skip it
            //
            // Если оператор уже был обработан выше, пропускаем
            if (in_array($operatorLower, $providedOperators, true)) {
                continue;
            }

            // Create an empty operator object (all fields will be null)
            //
            // Создаём пустой объект оператора (все поля будут null)
            $operatorClassName = $names->operator($filterOperator);
            if (
                class_exists($operatorClassName) &&
                property_exists($filterObject, $filterProperty)
            ) {
                $filterObject->$filterProperty = new $operatorClassName();
            }
        }
    }

    /**
     * Maps sort data into a {ModelType}Sort object.
     * A field outside config->sort or a direction other than asc|desc → 400;
     * there is a rule but no property in the DTO → 500 (drift of the generated class).
     *
     * Мапит данные сортировки в объект {ModelType}Sort.
     * Поле вне config->sort или направление не asc|desc → 400;
     * правило есть, свойства в DTO нет → 500 (дрейф сгенерированного класса).
     *
     * @param array<string|int, mixed> $sortData data from the query
     * @param SortRule[] $rules whitelist of fields
     * @throws InvalidListQueryException|ListActionMisconfiguredException
     */
    private function mapSort(
        array $sortData,
        string $sortClassName,
        array $rules,
        string $modelType,
    ): object {
        $sortObject = new $sortClassName();

        $allowed = [];
        foreach ($rules as $rule) {
            $allowed[$rule->path] = true;
        }

        foreach ($sortData as $fieldPath => $direction) {
            $fieldPath = (string) $fieldPath;

            if (!isset($allowed[$fieldPath])) {
                throw new InvalidListQueryException(
                    sprintf(
                        "Sort field '%s' is not allowed for model '%s'. Allowed: %s",
                        $fieldPath,
                        $modelType,
                        implode(", ", array_keys($allowed)),
                    ),
                );
            }

            $directionStr = is_string($direction) ? strtolower($direction) : null;
            if (!in_array($directionStr, ["asc", "desc"], true)) {
                throw new InvalidListQueryException(
                    sprintf(
                        "Sort direction for '%s' must be 'asc' or 'desc', %s given",
                        $fieldPath,
                        is_string($direction) ? "'" . $direction . "'" : get_debug_type($direction),
                    ),
                );
            }

            // There is a rule but no property in the DTO — drift of the generated class
            //
            // Правило есть, свойства в DTO нет — дрейф сгенерированного класса
            $propertyName = $this->resolvePropertyName($fieldPath);
            if (!property_exists($sortObject, $propertyName)) {
                throw new ListActionMisconfiguredException(
                    sprintf(
                        "Sort DTO %s has no property '%s' for configured rule '%s'",
                        $sortClassName,
                        $propertyName,
                        $fieldPath,
                    ),
                );
            }

            $sortObject->$propertyName = $directionStr;
            if ($sortObject instanceof TouchableDto) {
                $sortObject->markAsSet($propertyName);
            }
        }

        // required fields without a value stay null — the DTO validator checks them
        //
        // required-поля без значения остаются null — их проверяет валидатор DTO

        return $sortObject;
    }

    /**
     * Maps pagination data into a {ModelType}Pagination object
     *
     * Мапит данные пагинации в объект {ModelType}Pagination
     *
     * @param array<string|int, mixed> $pageData
     */
    private function mapPagination(
        array $pageData,
        string $paginationClassName,
    ): object {
        $paginationObject = new $paginationClassName();

        // Map number (page number)
        //
        // Мапим number (номер страницы)
        if (
            isset($pageData["number"]) &&
            is_numeric($pageData["number"]) &&
            property_exists($paginationObject, "number")
        ) {
            $paginationObject->number = (int) $pageData["number"];
            if ($paginationObject instanceof TouchableDto) {
                $paginationObject->markAsSet("number");
            }
        }

        // Map size (page size)
        //
        // Мапим size (размер страницы)
        if (
            isset($pageData["size"]) &&
            is_numeric($pageData["size"]) &&
            property_exists($paginationObject, "size")
        ) {
            $paginationObject->size = (int) $pageData["size"];
            if ($paginationObject instanceof TouchableDto) {
                $paginationObject->markAsSet("size");
            }
        }

        return $paginationObject;
    }
}
