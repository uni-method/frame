<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

use DateTimeInterface;
use UniMethod\Frame\Config\ActionType;
use UniMethod\Frame\Config\Common\Constraints\{ConstraintProperty, ConstraintRule};
use UniMethod\Frame\Type\TypeDef;
use UniMethod\Frame\Type\{TypeRegistry, TypeSpec};

final class ConstraintValidator
{
    public function __construct(
        private readonly RuleCatalog $catalog,
        private readonly ?TypeRegistry $registry = null,
    ) {
    }

    /**
     * Validation of an already cast value (after Caster).
     *
     * Валидация уже скастованного значения (после Caster).
     *
     * @return ConstraintViolation[]
     */
    public function validate(
        mixed $value,
        TypeSpec $type,
        ConstraintProperty $property,
        ?ActionType $context,
        string $path,
    ): array {
        $rules = $this->effectiveRules($type, $property, $context);

        if ($value === null) {
            return $this->checkNotNull($rules, $path);
        }

        if ($type->isList) {
            return [
                ...$this->applyRules($value, $this->ofScope($rules, RuleScope::Collection), $path),
                ...$this->validateItems($value, $type, $property, $path),
            ];
        }

        return $this->applyRules($value, $this->ofScope($rules, RuleScope::Value), $path);
    }

    /**
     * @param ConstraintRule[] $rules
     * @return ConstraintViolation[]
     */
    private function checkNotNull(array $rules, string $path): array
    {
        foreach ($rules as $rule) {
            if ($rule->type === RuleType::NotNull) {
                return [new ConstraintViolation($path, RuleType::NotNull, 'Value must not be null')];
            }
        }

        return [];
    }

    /**
     * Base + contextual + implicit rules of the declared type (enum → choice).
     *
     * Базовые + контекстные + неявные правила объявленного типа (enum → choice).
     *
     * @return ConstraintRule[]
     */
    private function effectiveRules(TypeSpec $type, ConstraintProperty $property, ?ActionType $context): array
    {
        $rules = [...$this->implicitTypeRules($type), ...$property->rules];

        return [...$rules, ...match ($context) {
            ActionType::Create => $property->create,
            ActionType::Update => $property->update,
            default => [],
        }];
    }

    /**
     * @return ConstraintRule[]
     */
    private function implicitTypeRules(TypeSpec $type): array
    {
        if ($type->declaredName === null) {
            return [];
        }

        $def = $this->registry?->find($type->declaredName);
        if (!$def instanceof TypeDef) {
            return [];
        }

        $rules = $def->rules;
        if ($def->enum !== null) {
            $rules[] = new ConstraintRule(RuleType::Choice, ['values' => $def->enum]);
        }

        return $rules;
    }

    /**
     * @param ConstraintRule[] $rules
     * @return ConstraintRule[]
     */
    private function ofScope(array $rules, RuleScope $scope): array
    {
        return array_values(array_filter(
            $rules,
            fn (ConstraintRule $r): bool => $this->catalog->get($r->type)->scope === $scope,
        ));
    }

    /**
     * @param iterable<array-key, mixed> $items
     * @return ConstraintViolation[]
     */
    private function validateItems(iterable $items, TypeSpec $type, ConstraintProperty $property, string $path): array
    {
        $itemRules = [...$this->implicitTypeRules($type), ...$property->each];
        if ($itemRules === []) {
            return [];
        }

        $violations = [];

        foreach ($items as $i => $item) {
            $violations = [
                ...$violations,
                ...$this->applyRules($item, $this->ofScope($itemRules, RuleScope::Value), $path . '[' . $i . ']'),
            ];
        }

        return $violations;
    }

    /**
     * @param ConstraintRule[] $rules
     * @return ConstraintViolation[]
     */
    private function applyRules(mixed $value, array $rules, string $path): array
    {
        $violations = [];

        foreach ($rules as $rule) {
            $message = $this->execute($rule, $value);
            if ($message !== null) {
                $violations[] = new ConstraintViolation($path, $rule->type, $message);
            }
        }

        return $violations;
    }

    private function execute(ConstraintRule $rule, mixed $value): ?string
    {
        $p = $rule->params;

        return match ($rule->type) {
            // handled before value rules are applied
            //
            // обработан до применения value-правил
            RuleType::NotNull => null,

            RuleType::NotBlank => trim((string) $value) === ''
                ? 'Value must not be blank'
                : null,

            RuleType::Length => $this->checkLength((string) $value, $p),

            RuleType::Regex => preg_match($p['pattern'], (string) $value) !== 1
                ? 'Value does not match pattern'
                : null,

            RuleType::Email => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? 'Value is not a valid email'
                : null,

            RuleType::Url => filter_var($value, FILTER_VALIDATE_URL) === false
                ? 'Value is not a valid URL'
                : null,

            RuleType::Range => $this->checkRange($value, $p),

            RuleType::Choice => in_array($value, $p['values'], true)
                ? null
                : 'Value is not in the allowed set',

            RuleType::Equals => $value !== $p['value']
                ? 'Value must equal the required value'
                : null,

            RuleType::Count => $this->checkCount($value, $p),

            RuleType::UniqueItems => count($value) !== count(array_unique($value, SORT_REGULAR))
                ? 'Items must be unique'
                : null,
        };
    }

    /**
     * @param array<string, mixed> $p
     */
    private function checkLength(string $value, array $p): ?string
    {
        $len = mb_strlen($value);

        if (isset($p['min']) && $len < $p['min']) {
            return sprintf('Length must be at least %d', $p['min']);
        }

        if (isset($p['max']) && $len > $p['max']) {
            return sprintf('Length must be at most %d', $p['max']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function checkRange(mixed $value, array $p): ?string
    {
        $min = $this->comparable($p['min'] ?? null);
        $max = $this->comparable($p['max'] ?? null);
        $val = $this->comparable($value);

        if ($min !== null && $val < $min) {
            return 'Value is below the allowed range';
        }

        if ($max !== null && $val > $max) {
            return 'Value is above the allowed range';
        }

        return null;
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value) && strtotime($value) !== false) {
            return strtotime($value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $p
     */
    private function checkCount(mixed $value, array $p): ?string
    {
        $count = is_countable($value) ? count($value) : iterator_count($value);

        if (isset($p['min']) && $count < $p['min']) {
            return sprintf('Collection must contain at least %d items', $p['min']);
        }

        if (isset($p['max']) && $count > $p['max']) {
            return sprintf('Collection must contain at most %d items', $p['max']);
        }

        return null;
    }
}
