# Mutators

## In the config

Mutators are grouped by processing phase (`PhaseType`):

- `afterInput` — after receiving the input data;
- `afterValidate` — after validation;
- `beforeOutput` — before returning the result.

They are declared at the attribute/relation level in **schema** (a list of aliases by phase), and
the implementing classes — in **impl** (`mutators` for attributes, `relationMutators`
for relations).

```json
{
  "schema": {
    "models": {
      "user": {
        "attributes": {
          "id": { "type": "int" },
          "email": {
            "type": "string",
            "writable": true,
            "mutators": {
              "afterInput": ["normalizeEmail"],
              "beforeOutput": ["maskEmail"]
            }
          }
        }
      }
    }
  },
  "impl": {
    "models": {
      "user": {
        "class": "App\\Entity\\User",
        "attributes": {
          "id": { "getter": "getId" },
          "email": { "getter": "getEmail", "setter": "setEmail" }
        }
      }
    },
    "mutators": {
      "normalizeEmail": "App\\Mutator\\NormalizeEmail",
      "maskEmail": "App\\Mutator\\MaskEmail"
    }
  }
}
```

```yaml
schema:
  models:
    user:
      attributes:
        id:
          type: int
        email:
          type: string
          writable: true
          mutators:
            afterInput: [normalizeEmail]
            beforeOutput: [maskEmail]
impl:
  models:
    user:
      class: 'App\Entity\User'
      attributes:
        id:
          getter: getId
        email:
          getter: getEmail
          setter: setEmail
  mutators:
    normalizeEmail: 'App\Mutator\NormalizeEmail'
    maskEmail: 'App\Mutator\MaskEmail'
```

Mutator classes must implement `AttributeMutatorInterface` (for
`mutators`) or `RelationMutatorInterface` (for `relationMutators`). The rules
of compatibility with setter/constructorParam are in [config.md](config.md), "Config validation".

## In code

A mutator is a transformation of an attribute or relation value at a certain processing
phase. It is registered under an alias and bound to a field in the config
(`mutators` by phase). Phases (`PhaseType`):

- `AfterInput` — after mapping the request body into the entity, before validation.
- `AfterValidate` — after successful validation, before saving.
- `BeforeOutput` — before serializing the response.

The attribute mutator interface:

```php
interface AttributeMutatorInterface
{
    public function apply(
        PhaseType $phase,
        object $entity,
        Model $model,
        string $name,
        mixed $value,
        ActionType $actionType,
    ): mixed;
}
```

`apply` receives the current value and returns a new one (or the same one). The returned
value is written back to the entity via the field's setter (or via
`mutatorSetter` if it is set separately in `impl`). For relations there is a similar
`RelationMutatorInterface` with the same signature.

```php
final class TrimMutator implements AttributeMutatorInterface
{
    public function apply(
        PhaseType $phase,
        object $entity,
        Model $model,
        string $name,
        mixed $value,
        ActionType $actionType,
    ): mixed {
        return is_string($value) ? trim($value) : $value;
    }
}
```

Mutators are put into `MutatorRegistry` as two maps (attribute and
relation) `alias => implementation`. A mutator for whose field neither the getter nor the setter
can be resolved is silently skipped — the value is not touched. An alias
that is not in the registry is skipped as well.
