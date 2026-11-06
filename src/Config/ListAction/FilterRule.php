<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

use UniMethod\Frame\Type\TypeSpec;

/**
 * Filtering rule for a field.
 *
 * Правило фильтрации для поля.
 */
final readonly class FilterRule
{
    /**
     * @param list<FilterOperator|FilterOperatorConfig> $operators Allowed operators (equal, in, like, range, etc.)
     * @param bool $required Whether this filter is required
     * @param FilterDefaultValue|null $defaultValue Default filter value
     * @param TypeSpec|null $typeSpec Custom field type; if passed, the model attribute is not looked up
     */
    public function __construct(
        public string $path, // "relation.city.name"
        public array $operators, // [FilterOperator::Equal, FilterOperator::Like]
        public bool $required = false,
        public ?FilterDefaultValue $defaultValue = null,
        public ?string $comment = null,
        public ?TypeSpec $typeSpec = null,
    ) {
    }
}
