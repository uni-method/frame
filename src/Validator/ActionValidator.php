<?php

declare(strict_types=1);

namespace UniMethod\Frame\Validator;

use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Config,
    Model,
    Relation,
    RelationWriteMode,
    WritableConfig,
};
use UniMethod\Frame\Exception\ConfigAlreadySetException;
use UniMethod\Frame\Mapping\Exception\{
    ActionNotConfiguredException,
    MissingIdAttributeException,
    ModelNotFoundException,
};
use UniMethod\Frame\Persistence\{
    EntityLocatorContainerInterface,
    LocatorNotConfiguredException,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Validator\Exception\{
    DeclaredRelationMissingException,
    DtoReferencedException,
    InvalidDataStructureException,
    RelationModeNotAllowedException,
    RelationNotIncludableException,
    RelationReferenceRequiresIdException,
    UndeclaredRelationException,
    UnknownPropertyException,
    UnknownRelationException,
    UnwritableRelationException,
};

class ActionValidator
{
    /** @var array<string, Model> */
    private array $modelsByType = [];

    /** @var array<string, array<string, Action>> */
    private array $actionsByModelAndType = [];

    /** @var array<string>|array<string, class-string<EntityLocatorContainerInterface>> */
    private array $locatorClassMap = [];

    /** @var array<string, string> Model locator by type (ModelLocatorResolver) */
    private array $locatorByModel = [];

    private bool $configured = false;

    public function __construct(
        private readonly TreeBuilder $treeBuilder,
        ?Config $config = null,
    ) {
        if ($config instanceof Config) {
            $this->setConfig($config);
        }
    }

    /**
     * The config is set once: here or in the constructor.
     *
     * Конфиг задаётся один раз: здесь либо в конструкторе.
     */
    public function setConfig(Config $config): void
    {
        if ($this->configured) {
            throw new ConfigAlreadySetException(self::class);
        }

        $this->configured = true;
        $this->modelsByType = [];
        $this->actionsByModelAndType = [];
        $this->locatorClassMap = $config->locators;
        $this->locatorByModel = [];

        foreach ($config->models as $model) {
            $this->modelsByType[$model->type] = $model;
        }

        foreach ($config->actions as $action) {
            $key = $action->type->value;
            $this->actionsByModelAndType[$action->model][$key] = $action;
        }

        // As in MappingToObject: the model locator — ModelLocatorResolver
        //
        // Как в MappingToObject: локатор модели — ModelLocatorResolver
        foreach ($config->models as $model) {
            $locator = $config->locatorForModel($model->type);
            if ($locator !== null) {
                $this->locatorByModel[$model->type] = $locator;
            }
        }
    }

    /**
     * Validation of data for an action
     *
     * Валидация данных для действия
     *
     * @param ActionType $actionType Action type
     * @param string $modelType Model type
     * @param object $stdFromJson Data from JSON (stdClass)
     * @param string|null $included API parameter: which relations are used (comma-separated, dot notation)
     * @throws ActionNotConfiguredException|ModelNotFoundException|UnwritableRelationException
     * @throws UnknownRelationException|RelationNotIncludableException|UnknownPropertyException|InvalidDataStructureException
     */
    public function validateStd(
        ActionType $actionType,
        string $modelType,
        object $stdFromJson,
        ?string $included = null,
    ): void {
        // Existence of the model and the action
        //
        // Существование модели и действия
        $model = $this->modelsByType[$modelType] ?? null;
        if (!$model) {
            throw new ModelNotFoundException($modelType);
        }

        // Check that the action exists for this model
        //
        // Проверяем существование действия для этой модели
        $action =
            $this->actionsByModelAndType[$modelType][$actionType->value] ??
            null;
        if (!$action) {
            throw new ActionNotConfiguredException($actionType->value, $modelType);
        }

        // Body validation
        //
        // Валидация тела
        $this->validateStdBody($stdFromJson, $model, $action, $actionType);

        // included validation
        //
        // Валидация included
        if ($included === null || trim($included) === "") {
            return;
        }

        if (
            $actionType === ActionType::Create ||
            $actionType === ActionType::Update
        ) {
            // For writing - check writable
            //
            // Для записи - проверяем writable
            $requestedTree = $this->treeBuilder->parseIncludedString($included);
            $configTree = $this->treeBuilder->buildConfigTree(
                $this->normalizeWritable($action->writable ?? []),
            );

            $this->treeBuilder->validateTreeSubset(
                $requestedTree,
                $configTree,
                $model,
                $this->modelsByType,
            );
        } else {
            // For reading - check include (exact match only)
            //
            // Для чтения - проверяем include (только точное совпадение)
            $requestedPaths = array_filter(
                array_map("trim", explode(",", $included)),
            );

            foreach ($requestedPaths as $path) {
                // Check that the path exists
                //
                // Проверяем что путь существует
                $this->treeBuilder->validatePathExists(
                    $model,
                    $path,
                    $this->modelsByType,
                );
            }
        }
    }

    /**
     * Input contract on create/update: `?included=` declares which relations
     * the client passes in the body. The body must match the declaration:
     *
     * - a relation (any value, including null and []) is present in the body but not
     *   declared → UndeclaredRelationException (422);
     * - a path is declared but missing from the body → DeclaredRelationMissingException (422).
     *
     * Matching of the declaration to the `writable` config is checked by validateStd().
     * For read/list/delete the method does nothing.
     *
     * Контракт входа на create/update: `?included=` декларирует, какие связи
     * клиент передаёт в теле. Тело обязано соответствовать декларации:
     *
     * - связь (любое значение, включая null и []) присутствует в теле, но не
     *   задекларирована → UndeclaredRelationException (422);
     * - путь задекларирован, но в теле отсутствует → DeclaredRelationMissingException (422).
     *
     * Соответствие декларации конфигу `writable` проверяет validateStd().
     * Для read/list/delete метод ничего не делает.
     *
     * @throws UndeclaredRelationException|DeclaredRelationMissingException|ModelNotFoundException
     */
    public function validateDeclaration(
        ActionType $actionType,
        string $modelType,
        object $stdFromJson,
        string $included,
    ): void {
        if (
            $actionType !== ActionType::Create &&
            $actionType !== ActionType::Update
        ) {
            return;
        }

        $model =
            $this->modelsByType[$modelType] ??
            throw new ModelNotFoundException($modelType);

        $declaredTree = $this->treeBuilder->parseIncludedString($included);

        $this->validateDeclarationNode($stdFromJson, $model, $declaredTree, $modelType, "");
    }

    /**
     * @param array<string, mixed> $declaredTree
     */
    private function validateDeclarationNode(
        object $data,
        Model $model,
        array $declaredTree,
        string $rootModelType,
        string $pathPrefix,
    ): void {
        $relationsByName = [];
        foreach ($model->relations as $relation) {
            $relationsByName[$relation->name] = $relation;
        }

        // 1. Relations in the body → each must be declared
        //
        // 1. Связи в теле → каждая должна быть задекларирована
        foreach (array_keys(get_object_vars($data)) as $key) {
            if (!isset($relationsByName[$key])) {
                continue;
            }

            $fullPath = $pathPrefix === "" ? $key : $pathPrefix . "." . $key;

            if (!array_key_exists($key, $declaredTree)) {
                throw new UndeclaredRelationException($rootModelType, $fullPath);
            }
        }

        // 2. Declaration → each path must be present in the body
        //
        // 2. Декларация → каждый путь должен присутствовать в теле
        foreach ($declaredTree as $key => $subtree) {
            if ($key === "__mode") {
                continue;
            }

            $fullPath = $pathPrefix === "" ? $key : $pathPrefix . "." . $key;

            if (!property_exists($data, $key)) {
                throw new DeclaredRelationMissingException($rootModelType, $fullPath);
            }

            // Not a relation (attribute/unknown key) — the area of validateStd
            //
            // Не связь (атрибут/неизвестный ключ) — зона validateStd
            $relation = $relationsByName[$key] ?? null;
            if (!$relation instanceof Relation) {
                continue;
            }

            $subtree = is_array($subtree) ? $subtree : [];
            $value = $data->{$key};

            // null — unlinking; a nested declaration under null cannot be fulfilled
            //
            // null — отвязывание; вложенная декларация под null невыполнима
            if ($value === null) {
                if ($subtree !== []) {
                    throw new DeclaredRelationMissingException(
                        $rootModelType,
                        $fullPath . "." . array_key_first($subtree),
                    );
                }

                continue;
            }

            $targetModel =
                $this->modelsByType[$relation->targetModel] ??
                throw new ModelNotFoundException($relation->targetModel);

            // Descend even with an empty sub-declaration: nested relations in the body
            // that are not in the declaration are caught by step 1 at the next level
            //
            // Спускаемся и при пустой поддекларации: вложенные связи в теле,
            // которых нет в декларации, ловит шаг 1 на следующем уровне
            $items = is_array($value) ? $value : [$value];
            foreach ($items as $item) {
                if (is_object($item)) {
                    $this->validateDeclarationNode(
                        $item,
                        $targetModel,
                        $subtree,
                        $rootModelType,
                        $fullPath,
                    );
                }
            }
        }
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function isRelationAllowed(
        string $requestedPath,
        array $allowedPaths,
    ): bool {
        // If there are no allowed relations - nothing is allowed
        //
        // Если нет разрешённых связей - ничего нельзя
        if ($allowedPaths === []) {
            return false;
        }

        // Exact match only: a nested path must be declared explicitly
        // (writable: { posts: create, "posts.tags": create }), prefixes are not
        // inherited — otherwise the write boundary becomes implicit
        //
        // Только точное совпадение: вложенный путь должен быть объявлен явно
        // (writable: { posts: create, "posts.tags": create }), префиксы не
        // наследуются — иначе граница записи становится неявной
        return in_array($requestedPath, $allowedPaths, true);
    }

    /**
     * Recursive validation of the action's data structure
     *
     * Рекурсивная валидация структуры данных действия
     */
    private function validateStdBody(
        object $data,
        Model $model,
        Action $action,
        ActionType $type,
        string $pathPrefix = "",
    ): void {
        // Normalize writable and include
        //
        // Нормализуем writable и include
        $allowedWritable = $this->normalizeWritable($action->writable ?? []);
        $isWriteAction =
            $type === ActionType::Create || $type === ActionType::Update;

        $relationsByName = [];
        foreach ($model->relations as $r) {
            $relationsByName[$r->name] = $r;
        }

        foreach (get_object_vars($data) as $key => $value) {
            // build the relative path
            //
            // формируем относительный путь
            $fullPath = $pathPrefix === "" ? $key : $pathPrefix . "." . $key;

            // if this is a model attribute
            //
            // если это атрибут модели
            if ($this->isModelAttribute($model, $key)) {
                continue;
            }

            // if this is a relation
            //
            // если это связь
            if (isset($relationsByName[$key])) {
                $relation = $relationsByName[$key];

                // for reading, include no longer restricts the allowed relations
                //
                // для чтения include больше не ограничивает допустимые связи
                $allowed =
                    !$isWriteAction ||
                    $this->isRelationAllowed(
                        $fullPath,
                        array_keys($allowedWritable),
                    );

                if (!$allowed && $relation->setter !== false) {
                    throw new UnwritableRelationException(
                        $model->type,
                        $fullPath,
                        $type->value,
                    );
                }

                // validate the structure (object/array/nullable)
                //
                // провалидировать структуру (объект/массив/nullable)
                $this->validateRelationStructure($relation, $value);

                // ADD THIS CHECK: null is always valid, does not require a mode
                //
                // ДОБАВИТЬ ЭТУ ПРОВЕРКУ: null всегда валиден, не требует режима
                if ($value === null) {
                    // skip mode validation for null
                    //
                    // пропускаем валидацию режимов для null
                    continue;
                }

                // Existence/mode validation ONLY for create/update
                //
                // Валидация существования/режима ТОЛЬКО для create/update
                if ($isWriteAction) {
                    // Empty arrays for collections are always valid
                    //
                    // Пустые массивы для коллекций всегда валидны
                    $isCollection = in_array(
                        $relation->type,
                        [Relation::ONE_TO_MANY, Relation::MANY_TO_MANY],
                        true,
                    );

                    if (!($isCollection && is_array($value) && $value === [])) {
                        $modeAllowed = $this->getAllowedModeForPath(
                            $allowedWritable,
                            $fullPath,
                        );

                        // Determine which mode follows from the data
                        //
                        // Определяем, какой режим вытекает из данных
                        $effectiveMode = $this->detectWriteModeFromPayload(
                            $value,
                            $relation,
                        );

                        if (
                            !$modeAllowed instanceof RelationWriteMode ||
                            !$this->isModeAllowed($effectiveMode, $modeAllowed)
                        ) {
                            throw new RelationModeNotAllowedException(
                                $model->type,
                                $fullPath,
                                $effectiveMode->value,
                                $type->value,
                            );
                        }

                        if ($effectiveMode === RelationWriteMode::Reference) {
                            $this->assertTargetHasId($relation, $fullPath);
                            $this->assertReferenceIdsNotNull($value, $fullPath);
                            $this->validateRelationExistenceWithLocators(
                                $relation,
                                $fullPath,
                            );
                        }
                    }
                }

                // recursive validation of the contents
                //
                // рекурсивная валидация содержимого
                $targetModel =
                    $this->modelsByType[$relation->targetModel] ??
                    throw new ModelNotFoundException($relation->targetModel);

                if (is_object($value)) {
                    $this->validateStdBody(
                        $value,
                        $targetModel,
                        $action,
                        $type,
                        $fullPath,
                    );
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_object($item)) {
                            $this->validateStdBody(
                                $item,
                                $targetModel,
                                $action,
                                $type,
                                $fullPath,
                            );
                        }
                    }
                }

                continue;
            }

            // unknown property
            //
            // неизвестное свойство
            throw new UnknownPropertyException($model->type, $key);
        }
    }

    private function isModelAttribute(Model $model, string $key): bool
    {
        foreach ($model->attributes as $attr) {
            if ($attr->name === $key) {
                return true;
            }
        }

        return false;
    }

    private function validateRelationStructure(
        Relation $relation,
        mixed $value,
    ): void {
        $isCollection = in_array(
            $relation->type,
            [Relation::ONE_TO_MANY, Relation::MANY_TO_MANY],
            true,
        );
        if ($isCollection && !is_array($value)) {
            throw new InvalidDataStructureException(
                sprintf(
                    "Relation '%s' expects an array of objects.",
                    $relation->name,
                ),
            );
        }

        if (!$isCollection && !is_object($value) && $value !== null) {
            throw new InvalidDataStructureException(
                sprintf(
                    "Relation '%s' expects an object or null.",
                    $relation->name,
                ),
            );
        }
    }

    /**
     * @param array<string, WritableConfig|RelationWriteMode> $writable
     * @return array<string, RelationWriteMode>
     */
    private function normalizeWritable(array $writable): array
    {
        // empty — return as is
        //
        // пусто — возвращаем как есть
        if ($writable === []) {
            return [];
        }

        $result = [];
        foreach ($writable as $path => $value) {
            $result[$path] =
                $value instanceof WritableConfig ? $value->mode : $value;
        }

        return $result;
    }

    /**
     * Returns the allowed mode for the path
     *
     * Возвращает разрешённый режим для пути
     *
     * @param array<string, RelationWriteMode> $normalizedWritable
     */
    private function getAllowedModeForPath(
        array $normalizedWritable,
        string $requestedPath,
    ): ?RelationWriteMode {
        // Exact match only, see isRelationAllowed
        //
        // Только точное совпадение, см. isRelationAllowed
        return $normalizedWritable[$requestedPath] ?? null;
    }

    /**
     * Determine the mode from the data
     * The logic does not depend on ActionType:
     * - If there is more than one property (including id) → Create
     * - If there is only one property "id" → Reference
     *
     * Определяем режим по данным
     * Логика не зависит от ActionType:
     * - Если больше одного свойства (включая id) → Create
     * - Если только одно свойство "id" → Reference
     */
    private function detectWriteModeFromPayload(
        mixed $value,
        Relation $relation,
    ): RelationWriteMode {
        $isCollection = in_array(
            $relation->type,
            [Relation::ONE_TO_MANY, Relation::MANY_TO_MANY],
            true,
        );

        if ($isCollection) {
            // For collections check each element
            //
            // Для коллекций проверяем каждый элемент
            foreach ($value as $item) {
                if (!is_object($item)) {
                    continue;
                }

                $vars = get_object_vars($item);
                $propCount = count($vars);

                // If there is more than one property or no id at all → Create
                //
                // Если больше одного свойства или нет id вообще → Create
                if ($propCount > 1 || !array_key_exists("id", $vars)) {
                    return RelationWriteMode::Create;
                }
            }

            // All elements have only id → Reference
            //
            // Все элементы имеют только id → Reference
            return RelationWriteMode::Reference;
        }

        // A single relation
        // FIX: null means unlinking, this is a Reference operation
        //
        // Одиночная связь
        // ИСПРАВЛЕНИЕ: null означает отвязывание, это Reference операция
        if ($value === null) {
            return RelationWriteMode::Reference;
        }

        if (!is_object($value)) {
            return RelationWriteMode::Create;
        }

        $vars = get_object_vars($value);
        $propCount = count($vars);

        // Only one property "id" → Reference
        // Everything else (more properties or no id) → Create
        //
        // Только одно свойство "id" → Reference
        // Всё остальное (больше свойств или нет id) → Create
        return $propCount === 1 && array_key_exists("id", $vars)
            ? RelationWriteMode::Reference
            : RelationWriteMode::Create;
    }

    /**
     * The reference `{"id": null}` is not a reference: there is nothing to load.
     *
     * Ссылка `{"id": null}` — не ссылка: загрузить нечего.
     *
     * @throws RelationReferenceRequiresIdException
     */
    /**
     * The reference target must have an `id` attribute — otherwise the reference cannot be resolved.
     *
     * Цель ссылки обязана иметь атрибут `id` — иначе ссылку не разрешить.
     *
     * @throws MissingIdAttributeException|ModelNotFoundException
     */
    private function assertTargetHasId(Relation $relation, string $path): void
    {
        $targetModel =
            $this->modelsByType[$relation->targetModel] ??
            throw new ModelNotFoundException($relation->targetModel);

        foreach ($targetModel->attributes as $attribute) {
            if ($attribute->name === "id") {
                return;
            }
        }

        throw new MissingIdAttributeException(
            $targetModel->type,
            sprintf("ActionValidator, relation '%s'", $path),
        );
    }

    private function assertReferenceIdsNotNull(mixed $value, string $path): void
    {
        $items = is_array($value) ? $value : [$value];
        foreach ($items as $item) {
            if (is_object($item) && ($item->id ?? null) === null) {
                throw new RelationReferenceRequiresIdException($path);
            }
        }
    }

    /**
     * The ReferenceOrCreate mode accepts both payload variants,
     * the others — only the matching one.
     *
     * Режим ReferenceOrCreate принимает оба варианта полезной нагрузки,
     * остальные — только совпадающий.
     */
    private function isModeAllowed(
        RelationWriteMode $effectiveMode,
        RelationWriteMode $allowedMode,
    ): bool {
        return $allowedMode === RelationWriteMode::ReferenceOrCreate ||
            $effectiveMode === $allowedMode;
    }

    /**
     * Checking the existence of entities for Reference via the locators of the target MODEL.
     * - if the target model has no locator → DtoReferencedException
     * - if the container is not passed → LocatorNotProvidedException
     * - if the locator name is not registered in Config->locators → LocatorNotConfiguredException
     *
     * Проверка существования сущностей для Reference через локаторы целевой МОДЕЛИ.
     * - если у целевой модели нет локатора → DtoReferencedException
     * - если контейнер не передан → LocatorNotProvidedException
     * - если имя локатора не зарегистрировано в Config->locators → LocatorNotConfiguredException
     */
    private function validateRelationExistenceWithLocators(
        Relation $relation,
        string $path,
    ): void {
        $targetModel =
            $this->modelsByType[$relation->targetModel] ??
            throw new ModelNotFoundException($relation->targetModel);

        $locatorName = $this->locatorByModel[$targetModel->type] ?? null;

        if ($locatorName === null) {
            throw new DtoReferencedException(
                $targetModel->type,
                $path,
                "reference",
            );
        }

        if (!isset($this->locatorClassMap[$locatorName])) {
            throw new LocatorNotConfiguredException($targetModel->type);
        }
    }
}
