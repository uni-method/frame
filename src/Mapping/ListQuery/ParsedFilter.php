<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery;

use UniMethod\Frame\Config\AccessConfig;
use UniMethod\Frame\Config\ListAction\{FilterOperator, FilterOperatorConfig, FilterRule};

/**
 * One element of the parsed query `filter[<operator>][<path>]=<value>`
 * that passed the whitelist: the path is declared in `config.filters`, the operator is in
 * the rule's `operators`. The value is raw, before type casting.
 *
 * Один элемент разобранного query `filter[<operator>][<path>]=<value>`,
 * прошедший вайтлист: путь объявлен в `config.filters`, оператор входит в
 * `operators` правила. Значение — сырое, до приведения типов.
 */
final readonly class ParsedFilter
{
    public function __construct(
        public FilterOperator $operator,
        public string $path,
        public FilterRule $rule,
        public mixed $value,
    ) {
    }

    /**
     * Access config of the operator from the extended form of the rule
     * (`{ operator, access }`), if it is set.
     *
     * Access-конфиг оператора из расширенной формы правила
     * (`{ operator, access }`), если он задан.
     */
    public function operatorAccess(): ?AccessConfig
    {
        foreach ($this->rule->operators as $entry) {
            if (
                $entry instanceof FilterOperatorConfig &&
                $entry->operator === $this->operator
            ) {
                return $entry->access;
            }
        }

        return null;
    }
}
