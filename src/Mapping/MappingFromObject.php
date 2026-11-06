<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping;

use ReflectionClass;
use ReflectionProperty;
use stdClass;
use UniMethod\Frame\Config\{ActionType, Config, Model, PhaseType, Relation};
use UniMethod\Frame\Exception\ConfigAlreadySetException;
use UniMethod\Frame\Mapping\Exception\{
    AttributeNotFoundException,
    ModelNotFoundException,
    ResponseTypeMismatchException
};
use UniMethod\Frame\Mutator\MutatorRegistry;
use UniMethod\Frame\Type\{Caster, TypeSpecParser};

class MappingFromObject
{
    /** @var array<string, Model> */
    private array $modelsByType = [];

    /** @var array<string, array<string, true>> public non-static properties by class */
    private array $publicPropertiesCache = [];

    /** @var array<string, array<string, true>> all declared properties by class */
    private array $declaredPropertiesCache = [];

    private bool $configured = false;

    public function __construct(
        private readonly MutatorRegistry $mutators,
        private readonly TypeSpecParser $typeSpecParser,
        private readonly Caster $caster,
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
        foreach ($config->models as $m) {
            $this->modelsByType[$m->type] = $m;
        }
    }

    /**
     * @param list<string>|null $include
     */
    public function map(
        string $type,
        object $instance,
        ?array $include = null,
        ?ActionType $actionType = null,
    ): object {
        $model = $this->modelsByType[$type] ?? null;
        if (!$model) {
            throw new ModelNotFoundException($type);
        }

        if (!$instance instanceof $model->class) {
            throw new ResponseTypeMismatchException(
                "class",
                $type,
                $model->class,
                get_class($instance),
            );
        }

        // Mutators (BeforeOutput)
        //
        // Мутаторы (BeforeOutput)
        if ($actionType instanceof ActionType) {
            $this->mutators->applyPhase(
                PhaseType::BeforeOutput,
                $instance,
                $model,
                $actionType,
            );
        }

        $result = new stdClass();

        /**
         * 1. Attributes
         *
         * 1. Атрибуты
         */
        foreach ($model->attributes as $attribute) {
            $getter = $this->resolveGetter(
                $instance,
                $attribute->getter,
                $attribute->name,
            );

            if ($getter === false || $getter === null) {
                continue;
            }

            $value = $this->getAttributeValue(
                $instance,
                $getter,
                $model->class,
            );

            $spec = $this->typeSpecParser->parse($attribute->type);

            if ($spec->isList && $value !== null && !is_array($value)) {
                throw new ResponseTypeMismatchException(
                    $model->type,
                    $attribute->name,
                    "array",
                    $value,
                );
            }

            $value = $this->caster->normalize($value, $spec);

            $result->{$attribute->name} = $value;
        }

        /**
         * 2. Relations
         *
         * 2. Связи
         */
        if ($include !== null && $include !== []) {
            $includeTree = $this->parseIncludeTree($include);

            foreach ($model->relations as $relation) {
                if (!array_key_exists($relation->name, $includeTree)) {
                    continue;
                }

                $getter = $this->resolveGetter(
                    $instance,
                    $relation->getter,
                    $relation->name,
                );

                if ($getter === false) {
                    continue;
                }

                if ($getter === null) {
                    throw new AttributeNotFoundException(
                        $model->class,
                        $relation->name,
                    );
                }

                $value = $this->getAttributeValue(
                    $instance,
                    $getter,
                    $model->class,
                );

                if ($value === null) {
                    $result->{$relation->name} = null;
                    continue;
                }

                $nestedInclude = $this->getNestedInclude(
                    $includeTree[$relation->name],
                );

                if (
                    $relation->type === Relation::ONE_TO_ONE ||
                    $relation->type === Relation::MANY_TO_ONE
                ) {
                    if (!is_object($value)) {
                        throw new ResponseTypeMismatchException(
                            $relation->targetModel,
                            $relation->type,
                            $relation->targetModel,
                            $value,
                        );
                    }

                    $result->{$relation->name} = $this->map(
                        $relation->targetModel,
                        $value,
                        $nestedInclude,
                        $actionType,
                    );
                }

                if (
                    $relation->type === Relation::ONE_TO_MANY ||
                    $relation->type === Relation::MANY_TO_MANY
                ) {
                    // Traversable (Doctrine Collection, etc.) works on a par
                    // with an array — a getXAsArray() wrapper in the entity is not needed
                    //
                    // Traversable (Doctrine Collection и т.п.) годится наравне
                    // с массивом — обёртка getXAsArray() в сущности не нужна
                    if (!is_iterable($value)) {
                        throw new ResponseTypeMismatchException(
                            $relation->targetModel,
                            $relation->type,
                            "iterable",
                            $value,
                        );
                    }

                    $mapped = [];
                    foreach ($value as $item) {
                        if (!is_object($item)) {
                            throw new ResponseTypeMismatchException(
                                $relation->targetModel,
                                $relation->type,
                                $relation->targetModel,
                                $item,
                            );
                        }

                        $mapped[] = $this->map(
                            $relation->targetModel,
                            $item,
                            $nestedInclude,
                            $actionType,
                        );
                    }

                    $result->{$relation->name} = $mapped;
                }
            }
        }

        return $result;
    }

    /*
     * ========================== helpers ==========================
     *
     * ========================== хелперы ==========================
     */

    private function resolveGetter(
        object $instance,
        string|null|false $configGetter,
        string $propertyName,
    ): string|null|false {
        if ($configGetter === false) {
            return false;
        }

        if (is_string($configGetter)) {
            return $configGetter;
        }

        if ($this->hasPublicProperty($instance, $propertyName)) {
            return $propertyName;
        }

        return null;
    }

    /**
     * Declared public non-static properties of the class — via Reflection, not
     * via get_object_vars(): the latter does not see uninitialized typed
     * properties, and a cache built from the first encountered instance would forever
     * "lose" such fields for all other objects of the class.
     *
     * Объявленные публичные нестатические свойства класса — по Reflection, а не
     * по get_object_vars(): последний не видит неинициализированные типизированные
     * свойства, и кэш, построенный по первому встреченному экземпляру, навсегда
     * «терял» бы такие поля для всех остальных объектов класса.
     *
     * @return array<string, true>
     */
    private function getPublicProperties(object $instance): array
    {
        $className = $instance::class;

        if (!isset($this->publicPropertiesCache[$className])) {
            $names = [];
            $declared = [];
            foreach ((new ReflectionClass($className))->getProperties() as $property) {
                $declared[$property->getName()] = true;
                if ($property->isPublic() && !$property->isStatic()) {
                    $names[$property->getName()] = true;
                }
            }

            $this->publicPropertiesCache[$className] = $names;
            $this->declaredPropertiesCache[$className] = $declared;
        }

        return $this->publicPropertiesCache[$className];
    }

    private function hasPublicProperty(
        object $instance,
        string $propertyName,
    ): bool {
        if (isset($this->getPublicProperties($instance)[$propertyName])) {
            return true;
        }

        // Declared but not public — do not output; not declared at all —
        // possibly a dynamic property (stdClass): ask the instance
        //
        // Объявлено, но не публично — не отдаём; не объявлено вовсе —
        // возможно динамическое свойство (stdClass): спрашиваем экземпляр
        if (isset($this->declaredPropertiesCache[$instance::class][$propertyName])) {
            return false;
        }

        return property_exists($instance, $propertyName);
    }

    private function getAttributeValue(
        object $instance,
        string $getter,
        string $modelClass,
    ): mixed {
        if (method_exists($instance, $getter)) {
            return $instance->{$getter}();
        }

        if (property_exists($instance, $getter)) {
            // An uninitialized typed property — there is no value
            // (previously such properties were invisible via get_object_vars and
            // silently dropped out of the output; now they are visible and output as null)
            //
            // Неинициализированное типизированное свойство — значения нет
            // (раньше такие свойства были невидимы через get_object_vars и
            // молча выпадали из выдачи; теперь они видны и отдаются как null)
            $property = new ReflectionProperty($instance, $getter);
            if (!$property->isInitialized($instance)) {
                return null;
            }

            return $instance->{$getter};
        }

        throw new AttributeNotFoundException($modelClass, $getter);
    }

    /**
     * @param list<string> $include
     * @return array<string, mixed>
     */
    private function parseIncludeTree(array $include): array
    {
        $tree = [];

        foreach ($include as $path) {
            $parts = explode(".", $path);
            /** @var array<string, mixed> $tree */
            $current = &$tree;

            foreach ($parts as $part) {
                if (!array_key_exists($part, $current)) {
                    $current[$part] = [];
                }

                $current = &$current[$part];
            }

            unset($current);
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $subtree
     * @return list<string>|null
     */
    private function getNestedInclude(array $subtree): ?array
    {
        if ($subtree === []) {
            return null;
        }

        $result = $this->flattenTree($subtree, "");

        return $result === [] ? null : $result;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<string>
     */
    private function flattenTree(array $tree, string $prefix): array
    {
        $result = [];

        foreach ($tree as $key => $subtree) {
            $path = $prefix === "" ? $key : $prefix . "." . $key;

            if ($subtree === []) {
                $result[] = $path;
            } else {
                foreach ($this->flattenTree($subtree, $path) as $sub) {
                    $result[] = $sub;
                }
            }
        }

        return $result;
    }
}
