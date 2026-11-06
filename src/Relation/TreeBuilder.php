<?php

declare(strict_types=1);

namespace UniMethod\Frame\Relation;

use UniMethod\Frame\Config\{Model, Relation, RelationWriteMode};
use UniMethod\Frame\Mapping\Exception\ModelNotFoundException;
use UniMethod\Frame\Validator\Exception\{
    InvalidWritableConfigModeException,
    UnknownRelationException,
    UnwritableRelationException,
};

/**
 * @phpstan-type IncludeTree array<string, mixed>
 * @phpstan-type IncludeTreeNode array<string, IncludeTree|list<mixed>>
 */
final class TreeBuilder
{
    /**
     * @return list<string>
     */
    public function simpleSplitAndUnique(?string $included): array
    {
        if ($included === null || trim($included) === "") {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(array_map("trim", explode(",", $included))),
            ),
        );
    }

    /**
     * @param list<string> $array1
     * @param list<string> $array2
     * @return list<string>
     */
    public function mergeArraysToUnique(array $array1, array $array2): array
    {
        return array_values(array_unique(array_merge($array1, $array2)));
    }

    /**
     * Parses the includes string into a tree without modes
     *
     * Парсит строку включений в дерево без режимов
     *
     * @param string|null $included "server,databases.backends,applications"
     * @return IncludeTreeNode Tree of the form ['server' => [], 'databases' => ['backends' => []]]
     */
    public function parseIncludedString(?string $included): array
    {
        $paths = $this->simpleSplitAndUnique($included);
        $tree = [];

        foreach ($paths as $path) {
            $parts = explode(".", $path);
            /** @var array<string, mixed> $current */
            $current = &$tree;

            foreach ($parts as $part) {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }

                $current = &$current[$part];
            }
        }

        return $tree;
    }

    /**
     * Builds a tree from the config with write modes
     *
     * Строит дерево из конфига с режимами записи
     *
     * @param array<string, RelationWriteMode|mixed> $writable
     * @return array<string, mixed> Tree with __mode
     */
    public function buildConfigTree(array $writable): array
    {
        $tree = [];

        foreach ($writable as $path => $mode) {
            if (!$mode instanceof RelationWriteMode) {
                throw new InvalidWritableConfigModeException((string) $path, $mode);
            }

            $parts = explode(".", $path);
            /** @var array<string, mixed> $current */
            $current = &$tree;

            foreach ($parts as $part) {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }

                $current = &$current[$part];
            }

            $current["__mode"] = $mode;
        }

        return $tree;
    }

    /**
     * Checks that all paths from requestedTree are present in configTree
     *
     * Проверяет что все пути из requestedTree присутствуют в configTree
     *
     * @param array<string, mixed> $requestedTree Requested tree
     * @param array<string, mixed> $configTree Configuration tree
     * @param Model $model Model for path validation
     * @param array<string, Model> $allModels All models from the config
     * @throws UnwritableRelationException|UnknownRelationException
     */
    public function validateTreeSubset(
        array $requestedTree,
        array $configTree,
        Model $model,
        array $allModels,
        string $pathPrefix = "",
    ): void {
        foreach ($requestedTree as $key => $subtree) {
            // Skip service keys
            //
            // Пропускаем служебные ключи
            if ($key === "__mode") {
                continue;
            }

            $currentPath = $pathPrefix === "" ? $key : $pathPrefix . "." . $key;

            // Check that the relation exists in the model
            //
            // Проверяем что связь существует в модели
            $relation = $this->findRelation($model, $key);
            if (!($relation instanceof Relation)) {
                throw new UnknownRelationException(
                    $model->type,
                    $key,
                    $currentPath,
                );
            }

            // Check that the relation is allowed in the config
            //
            // Проверяем что связь разрешена в конфиге
            if (!isset($configTree[$key])) {
                throw new UnwritableRelationException(
                    $model->type,
                    $currentPath,
                    $key,
                );
            }

            // Recursively check nested relations
            //
            // Рекурсивно проверяем вложенные связи
            if (is_array($subtree) && $subtree !== []) {
                $targetModel = $allModels[$relation->targetModel] ?? null;
                if (!$targetModel) {
                    throw new ModelNotFoundException($relation->targetModel);
                }

                $this->validateTreeSubset(
                    $subtree,
                    $configTree[$key],
                    $targetModel,
                    $allModels,
                    $currentPath,
                );
            }
        }
    }

    /**
     * Finds a relation by name in the model
     *
     * Ищет связь по имени в модели
     */
    public function findRelation(Model $model, string $name): ?Relation
    {
        foreach ($model->relations as $relation) {
            if ($relation->name === $name) {
                return $relation;
            }
        }

        return null;
    }

    /**
     * Merges the requested tree with the configuration one, adding modes
     *
     * Объединяет запрошенное дерево с конфигурационным, добавляя режимы
     *
     * @param array<string, mixed> $requestedTree Tree without modes
     * @param array<string, mixed> $configTree Tree with modes
     * @return array<string, mixed> Resulting tree with modes
     */
    public function mergeTreesWithModes(
        array $requestedTree,
        array $configTree,
    ): array {
        $result = [];

        foreach ($requestedTree as $key => $subtree) {
            if ($key === "__mode") {
                continue;
            }

            if (!isset($configTree[$key])) {
                // This should not happen after validateTreeSubset
                //
                // Это не должно произойти после validateTreeSubset
                continue;
            }

            $result[$key] = [];

            // Copy the mode from the config
            //
            // Копируем режим из конфига
            if (isset($configTree[$key]["__mode"])) {
                $result[$key]["__mode"] = $configTree[$key]["__mode"];
            }

            // Recursively process nested relations
            //
            // Рекурсивно обрабатываем вложенные связи
            if (is_array($subtree) && $subtree !== []) {
                $nested = $this->mergeTreesWithModes(
                    $subtree,
                    $configTree[$key],
                );
                $result[$key] = array_merge($result[$key], $nested);
            }
        }

        return $result;
    }

    /**
     * Gets the mode for a specific path from the tree
     *
     * Получает режим для конкретного пути из дерева
     *
     * @param array<string, mixed> $tree Tree with modes
     * @param string $path Path in dot notation
     */
    public function getModeForPath(
        array $tree,
        string $path,
    ): ?RelationWriteMode {
        $parts = explode(".", $path);
        $current = $tree;

        foreach ($parts as $part) {
            if (!isset($current[$part])) {
                return null;
            }

            $current = $current[$part];
        }

        return $current["__mode"] ?? null;
    }

    /**
     * Validates that the path exists in the chain of models
     *
     * Валидирует что путь существует в цепочке моделей
     *
     * @param array<string, Model> $allModels
     * @throws UnknownRelationException|ModelNotFoundException
     */
    public function validatePathExists(
        Model $model,
        string $path,
        array $allModels,
    ): void {
        $parts = explode(".", $path);
        $currentModel = $model;

        foreach ($parts as $index => $relationName) {
            $relation = $this->findRelation($currentModel, $relationName);

            if (!($relation instanceof Relation)) {
                throw new UnknownRelationException(
                    $currentModel->type,
                    $relationName,
                    implode(".", array_slice($parts, 0, $index + 1)),
                );
            }

            // Move to the next model if there are more path parts
            //
            // Переходим к следующей модели если есть ещё части пути
            if ($index < count($parts) - 1) {
                $currentModel = $allModels[$relation->targetModel] ?? null;
                if (!$currentModel) {
                    throw new ModelNotFoundException($relation->targetModel);
                }
            }
        }
    }

    /**
     * Checks whether the key is a model attribute
     *
     * Проверяет является ли ключ атрибутом модели
     */
    public function isAttribute(Model $model, string $name): bool
    {
        foreach ($model->attributes as $attr) {
            if ($attr->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds a simple tree from a list of paths (for include)
     *
     * Строит простое дерево из списка путей (для include)
     *
     * @param string[] $paths ['profile', 'posts.tags']
     * @return array<string, mixed> Tree without __modes
     */
    public function buildSimpleTree(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $parts = explode(".", $path);
            /** @var array<string, mixed> $current */
            $current = &$tree;

            foreach ($parts as $part) {
                if (!isset($current[$part]) || !is_array($current[$part])) {
                    $current[$part] = [];
                }

                $current = &$current[$part];
            }
        }

        return $tree;
    }
}
