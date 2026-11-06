<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

use UniMethod\Frame\Constraint\Exception\RuleCatalogNotReadableException;
use UniMethod\Frame\Type\{TypeSpec, ValueKind};

final class RuleCatalog
{
    /** @var array<string, RuleDef> */
    private array $defs = [];

    /**
     * @param array{rules: array<string, array<string, mixed>>} $data Decoded constraint-rules.json
     */
    public function __construct(array $data)
    {
        foreach ($data['rules'] as $name => $raw) {
            $this->defs[$name] = new RuleDef(
                type: RuleType::from($name),
                scope: RuleScope::from($raw['scope']),
                kinds: $raw['kinds'] === '*'
                    ? null
                    : array_map(ValueKind::from(...), $raw['kinds']),
                relations: $raw['relations'],
                requiresNullable: $raw['requiresNullable'] ?? false,
                params: $raw['params'] ?? [],
            );
        }
    }

    public static function fromJsonFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuleCatalogNotReadableException($path);
        }

        return new self(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function get(RuleType $type): RuleDef
    {
        return $this->defs[$type->value];
    }

    public function applies(RuleType $type, TypeSpec $spec, bool $isRelation): bool
    {
        $def = $this->get($type);

        if ($isRelation && !$def->relations) {
            return false;
        }

        if ($def->scope === RuleScope::Collection && !$spec->isList) {
            return false;
        }

        if ($def->scope === RuleScope::Value && $spec->isList) {
            // per element — via each
            //
            // на элемент — через each
            return false;
        }

        if ($def->requiresNullable && !$spec->nullable) {
            return false;
        }

        if (!$isRelation && $def->kinds !== null && !in_array($spec->kind, $def->kinds, true)) {
            return false;
        }

        return true;
    }
}
