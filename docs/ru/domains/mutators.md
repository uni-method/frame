# Мутаторы

## В конфиге

Мутаторы группируются по фазам обработки (`PhaseType`):

- `afterInput` — после приёма входных данных;
- `afterValidate` — после валидации;
- `beforeOutput` — перед выдачей результата.

Объявляются на уровне атрибута/связи в **schema** (список алиасов по фазам), а
реализующие классы — в **impl** (`mutators` для атрибутов, `relationMutators`
для связей).

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

Классы мутаторов должны реализовывать `AttributeMutatorInterface` (для
`mutators`) или `RelationMutatorInterface` (для `relationMutators`). Правила
сочетаемости с setter/constructorParam — в [config.md](config.md), «Валидация конфига».

## В коде

Мутатор — преобразование значения атрибута или связи на определённой фазе
обработки. Регистрируется под алиасом и привязывается к полю в конфиге
(`mutators` по фазам). Фазы (`PhaseType`):

- `AfterInput` — после маппинга тела запроса в сущность, до валидации.
- `AfterValidate` — после успешной валидации, до сохранения.
- `BeforeOutput` — перед сериализацией ответа.

Интерфейс мутатора атрибута:

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

`apply` получает текущее значение и возвращает новое (или то же). Возвращённое
значение записывается обратно в сущность через setter поля (или через
`mutatorSetter`, если он задан в `impl` отдельно). Для связей — аналогичный
`RelationMutatorInterface` с той же сигнатурой.

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

Мутаторы складываются в `MutatorRegistry` двумя мапами (атрибутные и
реляционные) `alias => реализация`. Мутатор, для поля которого не удаётся
разрешить и getter, и setter, тихо пропускается — значение не трогается. Алиас,
которого нет в реестре, тоже пропускается.
