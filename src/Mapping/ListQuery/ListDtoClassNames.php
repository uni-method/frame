<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery;

use UniMethod\Frame\Config\ListAction\FilterOperator;

/**
 * Names of the generated DTOs of a list action: namespace — from the root class
 * (`config.class`), base name — from the model class. A single scheme for
 * MappingToListAction and ConfigValidator.
 *
 * Имена сгенерированных DTO list-действия: namespace — от корневого класса
 * (`config.class`), базовое имя — от класса модели. Единая схема для
 * MappingToListAction и ConfigValidator.
 */
final readonly class ListDtoClassNames
{
    private string $prefix;

    private string $origin;

    public function __construct(string $rootClass, string $modelClass)
    {
        $rootSeparator = strrpos($rootClass, "\\");
        $this->prefix =
            $rootSeparator === false || $rootSeparator === 0
                ? ""
                : substr($rootClass, 0, $rootSeparator + 1);

        $modelSeparator = strrpos($modelClass, "\\");
        $this->origin =
            $modelSeparator === false
                ? $modelClass
                : substr($modelClass, $modelSeparator + 1);
    }

    public function filter(): string
    {
        return $this->prefix . $this->origin . "Filter";
    }

    public function sort(): string
    {
        return $this->prefix . $this->origin . "Sort";
    }

    public function pagination(): string
    {
        return $this->prefix . $this->origin . "Pagination";
    }

    public function operator(FilterOperator $operator): string
    {
        return $this->prefix . $this->origin . $operator->name;
    }
}
