<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping;

use InvalidArgumentException;
use UniMethod\Frame\Config\{
    Action,
    ActionType,
    Attribute,
    Config,
    Model,
    ModelLocatorResolver,
    Relation,
    RelationWriteMode,
    WritableConfig,
};
use UniMethod\Frame\Exception\ConfigAlreadySetException;
use UniMethod\Frame\Mapping\Exception\{
    ActionNotConfiguredException,
    CollectionReplaceRequiresSetterException,
    EntityInstantiationException,
    MissingEntityLocatorException,
    MissingIdAttributeException,
    ModelNotFoundException,
    RelationTypeMismatchException,
    TargetTypeMismatchException,
    TypeMismatchException,
    UpdateRequiresIdException,
};
use UniMethod\Frame\Persistence\{
    EntityLocatorContainerInterface,
    EntityNotFoundException,
};
use UniMethod\Frame\Relation\TreeBuilder;
use UniMethod\Frame\Type\{Caster, TypeSpecParser};
use UniMethod\Frame\Validator\Exception\RelationReferenceRequiresIdException;

class MappingToObject
{
    private const string PK = "id";

    /** @var array<string, Model> */
    private array $modelsByType = [];

    /** @var array<string, array<string, Action>> */
    private array $actionsByModelAndType = [];

    /** @var array<string, string> Model locator by type (ModelLocatorResolver) */
    private array $locatorByModel = [];

    /**
     * Locator of the currently executing action (loading the root on Update)
     *
     * Локатор текущего выполняемого action (загрузка корня при Update)
     */
    private bool $configured = false;

    public function __construct(
        private readonly TreeBuilder $treeBuilder,
        private readonly TypeSpecParser $typeSpecParser,
        private readonly Caster $caster,
        private readonly EntityLocatorContainerInterface $locatorContainer,
        private readonly AttributeAccessor $attributeAccessor,
        ?Config $config = null,
    ) {
        if ($config instanceof Config) {
            $this->setConfig($config);
        }
    }

    /**
     * The config is set once: here or in the constructor. A repeated call —
     * ConfigAlreadySetException (indexes are not rebuilt under load).
     *
     * Конфиг задаётся один раз: здесь либо в конструкторе. Повторный вызов —
     * ConfigAlreadySetException (индексы не перестраиваются под нагрузкой).
     */
    public function setConfig(Config $config): void
    {
        if ($this->configured) {
            throw new ConfigAlreadySetException(self::class);
        }

        $this->configured = true;
        $this->modelsByType = [];
        $this->actionsByModelAndType = [];
        $this->locatorByModel = [];

        foreach ($config->models as $m) {
            $this->modelsByType[$m->type] = $m;
        }

        foreach ($config->actions as $action) {
            $this->actionsByModelAndType[$action->model][
                $action->type->value
            ] = $action;
        }

        // Model locator for references — ModelLocatorResolver
        //
        // Локатор модели для ссылок — ModelLocatorResolver
        foreach ($config->models as $m) {
            $locator = $config->locatorForModel($m->type);
            if ($locator !== null) {
                $this->locatorByModel[$m->type] = $locator;
            }
        }
    }

    /**
     * Public method - entry point
     *
     * Публичный метод - точка входа
     *
     * @param string $type Model type
     * @param object $object Input data (stdClass)
     * @param ActionType $actionType Action type (Create/Update)
     * @param string|null $included Input declaration ("author,items.product"): only
     *        the intersection of the declaration and writable is written; the body matching the declaration
     *        is checked by ActionValidator::validateDeclaration
     * @param int|string|null $rootId Only for editing PATCH /model/123 - 123 is exactly the id
     * @param list<string> $includeForLocator List of paths for eager loading in the locator
     * @throws RelationTypeMismatchException
     */
    public function map(
        string $type,
        object $object,
        ActionType $actionType,
        ?string $included = null,
        int|string|null $rootId = null,
        array $includeForLocator = [],
        ?object $target = null,
    ): object {
        $model = $this->modelsByType[$type] ?? null;

        if (!$model) {
            throw new ModelNotFoundException($type);
        }

        $action =
            $this->actionsByModelAndType[$type][$actionType->value] ?? null;

        if (!$action) {
            throw new ActionNotConfiguredException($actionType->value, $type);
        }

        if ($actionType === ActionType::Update && $rootId === null) {
            throw new UpdateRequiresIdException($type, self::PK);
        }

        // Write tree = declaration (?included=) ∩ writable (config), with
        // modes from the config. Paths are compared exactly, prefixes are not inherited.
        //
        // Дерево записи = декларация (?included=) ∩ writable (конфиг), с
        // режимами из конфига. Пути сравниваются точно, префиксы не наследуются.
        $requestedTree = $this->treeBuilder->parseIncludedString($included);
        $writableModes = array_map(
            static fn (WritableConfig $wc): RelationWriteMode => $wc->mode,
            $action->writable ?? [],
        );
        $configTree = $this->treeBuilder->buildConfigTree($writableModes);
        $writableTree = $this->treeBuilder->mergeTreesWithModes(
            $requestedTree,
            $configTree,
        );

        // Build the tree for include (to pass to the locator)
        //
        // Строим дерево для include (для передачи в locator)
        $includeTree = $this->treeBuilder->buildSimpleTree($includeForLocator);

        return $this->doMap(
            $model,
            $object,
            $writableTree,
            $actionType,
            $includeTree,
            true,
            $rootId,
            $target,
            $action->entityLocator,
        );
    }

    /**
     * Private recursive method
     * Pure update
     *
     * Приватный рекурсивный метод
     * В чистом виде апдейт
     *
     * @param Model $model Model
     * @param object $object Input data (stdClass)
     * @param array<string, mixed> $writableTree Tree with modes
     * @param ActionType $actionType Action type
     * @param array<string, mixed> $includeTree Include tree to pass to the locator
     * @param object|null $target An already loaded entity for Update (see map())
     * @param string|null $actionLocator Locator alias of the current action (Update root only)
     * @throws RelationTypeMismatchException
     */
    private function doMap(
        Model $model,
        object $object,
        array $writableTree,
        ActionType $actionType,
        array $includeTree = [],
        bool $rootModel = false,
        int|string|null $rootId = null,
        ?object $target = null,
        ?string $actionLocator = null,
    ): object {
        // For Update write into the existing object: the one passed from outside or
        // the one loaded by the locator
        //
        // Для Update пишем в существующий объект: переданный снаружи либо
        // загруженный локатором
        $instance = null;

        if ($actionType === ActionType::Update && $rootModel && $target !== null) {
            if (!$target instanceof $model->class) {
                throw new TargetTypeMismatchException(
                    $model->type,
                    $model->class,
                    $target::class,
                );
            }

            $instance = $target;
        } elseif ($actionType === ActionType::Update && $rootModel) {
            if ($rootId === null) {
                throw new UpdateRequiresIdException($model->type, self::PK);
            }

            // The action locator is an argument, not a field: the service is a singleton,
            // request state on it is not allowed (see Swoole coroutines)
            //
            // Локатор действия — аргументом, не полем: сервис — синглтон,
            // запросное состояние на нём недопустимо (см. корутины Swoole)
            $modelLocator =
                $actionLocator ??
                ($this->locatorByModel[$model->type] ?? null);
            if ($modelLocator === null) {
                throw new MissingEntityLocatorException($model->type);
            }

            $locator = $this->locatorContainer->getEntityLocatorByName(
                $modelLocator,
            );
            $includeForLocator = $this->flattenIncludeTree($includeTree);
            $instance = $locator->find(
                $model->class,
                $rootId,
                $includeForLocator,
                $object,
            );

            if ($instance === null) {
                throw new EntityNotFoundException($model->class, $rootId);
            }
        }

        [$constructorAttrs, $regularAttrs] = $this->splitByConstructorFlag(
            $model->attributes,
        );
        [
            $constructorRelations,
            $regularRelations,
        ] = $this->splitByConstructorFlag($model->relations);

        // 1. collect constructor parameters (Create only)
        //
        // 1. собираем параметры конструктора (только для Create)
        if ($instance === null) {
            $constructorParams = [];

            foreach ($constructorAttrs as $attribute) {
                $value = $object->{$attribute->name} ?? null;
                $spec = $this->typeSpecParser->parse($attribute->type);
                try {
                    $value = $this->caster->cast($value, $spec);
                } catch (InvalidArgumentException $e) {
                    throw new TypeMismatchException(
                        $model->type,
                        $attribute->name,
                        $attribute->type,
                        $value,
                        $e,
                    );
                }

                $constructorParams[$attribute->name] = $value;
            }

            foreach ($constructorRelations as $relation) {
                $node = $writableTree[$relation->name] ?? null;
                if (!is_array($node)) {
                    $constructorParams[$relation->name] = null;
                    continue;
                }

                $mode = $node["__mode"] ?? RelationWriteMode::Create;
                $nestedWritable = $node;
                unset($nestedWritable["__mode"]);

                // Get the nested includeTree for the relation
                //
                // Получаем вложенный includeTree для связи
                $nestedIncludeTree = $includeTree[$relation->name] ?? [];

                $value = $object->{$relation->name} ?? null;
                if ($value === null) {
                    $constructorParams[$relation->name] = null;
                    continue;
                }

                $constructorParams[$relation->name] = $this->mapRelationValue(
                    $relation,
                    $value,
                    $nestedWritable,
                    $mode,
                    $nestedIncludeTree,
                );
            }

            try {
                $instance =
                    $constructorParams === []
                        ? new $model->class()
                        : new $model->class(...$constructorParams);
            } catch (\Error $error) {
                throw new EntityInstantiationException(
                    $model->class,
                    $model->type,
                    $error->getMessage(),
                    $error,
                );
            }
        }

        // 2. regular attributes
        //
        // 2. обычные атрибуты

        // Remove mapping for attributes whose setter is disabled
        //
        // Убираем маппинг для тех аттрибутов у которых отключен setter
        $attrsForWriting = array_filter(
            $regularAttrs,
            static fn (Attribute $attr): bool => $attr->setter !== false,
        );

        foreach ($attrsForWriting as $attribute) {
            // For Update check that the field was actually passed
            //
            // Для Update проверяем, что поле действительно передано
            if (
                $actionType === ActionType::Update &&
                !property_exists($object, $attribute->name)
            ) {
                // Skip fields that were not passed
                //
                // Пропускаем непереданные поля
                continue;
            }

            $value = $object->{$attribute->name} ?? null;
            $spec = $this->typeSpecParser->parse($attribute->type);

            try {
                $value = $this->caster->cast($value, $spec);
            } catch (InvalidArgumentException $e) {
                throw new TypeMismatchException(
                    $model->type,
                    $attribute->name,
                    $attribute->type,
                    $value,
                    $e,
                );
            }

            $setter = $this->resolveSetter(
                $instance,
                $attribute->setter,
                $attribute->name,
            );
            if ($setter !== false && $setter !== null) {
                $this->setAttributeValue($instance, $setter, $value);
            }
        }

        // 3. regular relations
        //
        // 3. обычные связи

        // Remove mapping for relations whose setter and adder are disabled
        //
        // Убираем маппинг для тех cвязей у которых отключен setter и adder
        $relationsForWriting = array_filter(
            $regularRelations,
            static fn (Relation $rel): bool => $rel->setter !== false || $rel->add !== false,
        );

        foreach ($relationsForWriting as $relation) {
            $node = $writableTree[$relation->name] ?? null;
            if (!is_array($node)) {
                continue;
            }

            // For Update check that the relation was actually passed
            //
            // Для Update проверяем, что связь действительно передана
            if (
                $actionType === ActionType::Update &&
                !property_exists($object, $relation->name)
            ) {
                // Skip relations that were not passed
                //
                // Пропускаем непереданные связи
                continue;
            }

            $mode = $node["__mode"] ?? RelationWriteMode::Create;
            $nestedWritable = $node;
            unset($nestedWritable["__mode"]);

            // Get the nested includeTree for the relation
            //
            // Получаем вложенный includeTree для связи
            $nestedIncludeTree = $includeTree[$relation->name] ?? [];

            $value = $object->{$relation->name} ?? null;

            // If value === null, still process it (for unlinking)
            //
            // Если value === null, все равно обрабатываем (для отвязывания)
            $setter = $this->resolveSetter(
                $instance,
                $relation->setter,
                $relation->name,
            );
            $isCollection = in_array(
                $relation->type,
                [Relation::ONE_TO_MANY, Relation::MANY_TO_MANY],
                true,
            );

            $hasSetter = $setter !== false && $setter !== null;

            // Updating a collection — PATCH semantics: a key in the body = full replacement,
            // null ≡ [] (remove all). Replacement is possible only via the setter;
            // `add` appends but does not remove
            //
            // Update коллекции — PATCH-семантика: ключ в теле = полная замена,
            // null ≡ [] (убрать всех). Замена возможна только через setter;
            // `add` дописывает, но не удаляет
            if ($isCollection && $actionType === ActionType::Update && !$hasSetter) {
                throw new CollectionReplaceRequiresSetterException(
                    $model->type,
                    $relation->name,
                );
            }

            if ($value === null) {
                // null: a single relation — unlinking, a collection — empty
                //
                // null: одиночная связь — отвязывание, коллекция — пустая
                if ($hasSetter) {
                    $this->setRelationValue($instance, $setter, $isCollection ? [] : null);
                }

                continue;
            }

            // Handling non-empty values
            //
            // Обработка непустых значений
            $mappedValue = $this->mapRelationValue(
                $relation,
                $value,
                $nestedWritable,
                $mode,
                $nestedIncludeTree,
            );

            if ($isCollection) {
                $add = $relation->add;

                // Priority: if there is a setter - use only it
                //
                // Приоритет: если есть setter - используем только его
                if ($hasSetter) {
                    $this->setRelationValue($instance, $setter, $mappedValue);
                } elseif (is_string($add) && method_exists($instance, $add)) {
                    // Create without a setter: fill via add
                    //
                    // Create без setter: наполняем через add
                    foreach ($mappedValue as $mv) {
                        $instance->{$add}($mv);
                    }
                }
            } elseif ($hasSetter) {
                $this->setRelationValue($instance, $setter, $mappedValue);
            }
        }

        return $instance;
    }

    /**
     * @param array<string, mixed> $nestedWritable
     * @param array<string, mixed> $includeTree
     * @throws RelationTypeMismatchException
     */
    private function mapRelationValue(
        Relation $relation,
        mixed $value,
        array $nestedWritable,
        RelationWriteMode $mode,
        array $includeTree = [],
    ): mixed {
        $targetModel = $this->modelsByType[$relation->targetModel] ?? null;
        if (!$targetModel) {
            throw new ModelNotFoundException($relation->targetModel);
        }

        $isCollection = in_array(
            $relation->type,
            [Relation::ONE_TO_MANY, Relation::MANY_TO_MANY],
            true,
        );

        if ($isCollection) {
            if (!is_array($value)) {
                throw new RelationTypeMismatchException(
                    $relation->targetModel,
                    $relation->name,
                    "array",
                    gettype($value),
                );
            }

            $mapped = [];
            foreach ($value as $item) {
                if (!is_object($item)) {
                    throw new RelationTypeMismatchException(
                        $relation->targetModel,
                        $relation->name,
                        "object",
                        gettype($item),
                    );
                }

                $mapped[] = $this->mapSingleRelation(
                    $targetModel,
                    $item,
                    $nestedWritable,
                    $mode,
                    $includeTree,
                );
            }

            return $mapped;
        }

        if (!is_object($value)) {
            throw new RelationTypeMismatchException(
                $relation->targetModel,
                $relation->name,
                "object",
                gettype($value),
            );
        }

        return $this->mapSingleRelation(
            $targetModel,
            $value,
            $nestedWritable,
            $mode,
            $includeTree,
        );
    }

    /**
     * This is mapping of a nested model
     *
     * Это маппинг вложенной модели
     *
     * @param array<string, mixed> $nestedWritable
     * @param array<string, mixed> $includeTree
     * @throws RelationTypeMismatchException
     */
    private function mapSingleRelation(
        Model $targetModel,
        object $value,
        array $nestedWritable,
        RelationWriteMode $mode,
        array $includeTree = [],
    ): object {
        if (
            $mode === RelationWriteMode::Reference ||
            ($mode === RelationWriteMode::ReferenceOrCreate &&
                $this->isReferencePayload($value))
        ) {
            return $this->resolveReference($targetModel, $value, $includeTree);
        }

        // Otherwise create a new one
        //
        // Иначе создаём новую
        return $this->doMap(
            $targetModel,
            $value,
            $nestedWritable,
            ActionType::Create,
            $includeTree,
        );
    }

    /**
     * A reference is an object with exactly one id property, as in ActionValidator.
     *
     * Ссылка — объект ровно с одним свойством id, как и в ActionValidator.
     */
    private function isReferencePayload(object $value): bool
    {
        $vars = get_object_vars($value);

        return count($vars) === 1 && array_key_exists(self::PK, $vars);
    }

    /**
     * @template T of Attribute|Relation
     * @param array<T> $items
     * @return array{0: list<T>, 1: list<T>}
     */
    private function splitByConstructorFlag(array $items): array
    {
        $constructor = [];
        $regular = [];
        foreach ($items as $item) {
            if ($item->constructorParam) {
                $constructor[] = $item;
            } else {
                $regular[] = $item;
            }
        }

        return [$constructor, $regular];
    }

    /**
     * @param array<string, mixed> $includeTree
     */
    private function resolveReference(
        Model $targetModel,
        object $value,
        array $includeTree = [],
    ): object {
        $targetLocator = $this->locatorByModel[$targetModel->type] ?? null;
        if ($targetLocator === null) {
            throw new MissingEntityLocatorException(
                $targetModel->type,
                "Reference mode",
                ModelLocatorResolver::hint($targetModel->type),
            );
        }

        $idAttr = null;
        foreach ($targetModel->attributes as $attr) {
            if ($attr->name === self::PK) {
                $idAttr = $attr;
                break;
            }
        }

        if ($idAttr === null) {
            // Invariant for manual assembly of Config: the loader will not let this through
            //
            // Инвариант для ручной сборки Config: загрузчик такого не пропустит
            throw new MissingIdAttributeException($targetModel->type, "MappingToObject::resolveReference");
        }

        $id = $value->{$idAttr->name} ?? null;
        if ($id === null) {
            throw new RelationReferenceRequiresIdException($targetModel->type);
        }

        $locator = $this->locatorContainer->getEntityLocatorByName(
            $targetLocator,
        );
        $includeForLocator = $this->flattenIncludeTree($includeTree);
        $entity = $locator->find($targetModel->class, $id, $includeForLocator);

        if ($entity === null) {
            throw new EntityNotFoundException($targetModel->class, $id);
        }

        return $entity;
    }

    /**
     * Converts the include tree into a flat list of paths
     *
     * Преобразует дерево include в плоский список путей
     *
     * @param array<string, mixed> $tree Tree of the form ['profile' => [], 'posts' => ['tags' => []]]
     * @param string $prefix Current path prefix
     * @return list<string> List of paths of the form ['profile', 'posts', 'posts.tags']
     */
    private function flattenIncludeTree(array $tree, string $prefix = ""): array
    {
        $result = [];

        foreach ($tree as $key => $subtree) {
            if ($key === "__mode") {
                continue;
            }

            $path = $prefix === "" ? $key : $prefix . "." . $key;
            $result[] = $path;

            if (is_array($subtree) && $subtree !== []) {
                $result = array_merge(
                    $result,
                    $this->flattenIncludeTree($subtree, $path),
                );
            }
        }

        return $result;
    }

    private function resolveSetter(
        object $instance,
        string|null|false $configSetter,
        ?string $propertyName,
    ): string|null|false {
        return $this->attributeAccessor->resolveSetter(
            $instance,
            $configSetter,
            $propertyName,
        );
    }

    private function setAttributeValue(
        object $instance,
        string $setter,
        mixed $value,
    ): void {
        $this->attributeAccessor->setValue($instance, $setter, $value);
    }

    private function setRelationValue(
        object $instance,
        string $setter,
        mixed $value,
    ): void {
        $this->attributeAccessor->setValue($instance, $setter, $value);
    }
}
