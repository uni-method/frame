# Конфиг

Описание конфигурации фреймворка `UniMethod\Frame`: как писать конфиг, как
сгенерировать его под задачу и как проверить готовый. Все примеры даны парами —
сначала JSON, потом эквивалентный YAML.

Конфиг загружается одним из загрузчиков:

- `UniMethod\Frame\Config\JsonConfigLoader` — `load(path)` / `loadFromString(str)`.
- `UniMethod\SymfonyLib\Config\YamlConfigLoader` — то же API, парсер Symfony Yaml.

Оба наследуют `AbstractConfigLoader` и используют одну и ту же логику разбора,
валидации и сборки. **Структура данных для JSON и YAML идентична** — отличается
только синтаксис файла и класс исключения при ошибке парсинга (см.
«Ошибки парсинга»).

## Общая модель

Корень конфига — объект (мапа) с двумя обязательными секциями:

- `schema` — **контракт**: какие есть модели, атрибуты, связи, действия, какие
  поля доступны для записи/включения, правила list (пагинация, сортировка,
  фильтры). Описывает «что», не привязано к конкретной реализации сущностей.
- `impl` — **реализация**: PHP-классы сущностей, как читать/писать поля
  (getter/setter/add), параметры конструктора, словари сервисов
  (locators/savers/removers/mutators/relationMutators), `userModel`.
- `constraints` — **ограничения** (необязательная секция): декларативные правила
  на значения атрибутов и связей (длины, диапазоны, форматы, обязательность по
  контексту create/update). Сопоставляется со schema по тем же ключам. Это
  **входные данные прибилда**, а не рабочего конфига: прибилд проверяет её,
  порождает из неё нативный артефакт проверки для целевого языка (для PHP —
  метаданные Symfony Validator) и выдаёт рабочий конфиг уже без неё. В рантайме
  правила применяет адаптер `ConstraintCheckerInterface`. Подробно —
  [constraints.md](constraints.md). Там же — секция `types` (переиспользуемые
  именованные типы: enum, семантические типы поверх скаляров).

Обе секции обязаны присутствовать и быть объектами. Пустые секции допустимы.

Загрузчик выполняет шаги в строгом порядке:

1. Разбирает `schema` → внутренние Schema-DTO.
2. Разбирает `impl` → внутренние Impl-DTO.
3. Валидирует impl-словари, отсутствие «осиротевших» impl-записей, **полноту
   impl относительно schema** и согласованность schema↔impl **до** слияния.
4. Сливает schema и impl в единый `Config` (`Merger`).
5. Прогоняет пост-валидации над слитым конфигом (id, фильтры, сервисы,
   мутаторы, userModel).

Минимальный валидный конфиг — пустые секции:

```json
{
  "schema": {},
  "impl": {}
}
```

```yaml
schema: {}
impl: {}
```

Минимальный осмысленный конфиг — одна модель с атрибутом `id`:

```json
{
  "schema": {
    "models": {
      "user": { "attributes": { "id": { "type": "int" } } }
    }
  },
  "impl": {
    "models": {
      "user": {
        "class": "App\\Entity\\User",
        "attributes": { "id": { "getter": "getId" } }
      }
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
impl:
  models:
    user:
      class: 'App\Entity\User'
      attributes:
        id:
          getter: getId
```

> Обратите внимание на экранирование обратных слешей: в JSON класс пишется как
> `App\\Entity\\User`, в YAML внутри одинарных кавычек — как `App\Entity\User`.

## Ключи как идентификаторы

Везде, где элементы именуются (модели, атрибуты, связи), **имя — это ключ
объекта**, а не поле внутри:

- `schema.models` — мапа `тип_модели → описание`. Тип произвольный строковый
  (`user`, `order_item`, `project_dto`).
- `attributes` — мапа `имя_атрибута → описание`.
- `relations` — мапа `имя_связи → описание`.
- `schema.actions` — мапа `тип_модели → { тип_действия → описание }`.

Списки (например `["profile", "roles"]`) — это именно последовательности. Если
вместо мапы передать список там, где ждут мапу (или наоборот), загрузчик бросит
ошибку вида `... must be an object, not an array` / `... must be a list`.

Имена методов/свойств (getter, setter, add, mutatorSetter) валидируются как
PHP-идентификаторы: буквы, цифры, `_`, UTF-8-буквы; не начинаются с цифры.

## Где описаны части конфига

- модели, атрибуты, связи (`schema.models`, `impl.models`) — [models.md](models.md);
- действия, `writable`/`include`/`includable` (`schema.actions`, `impl.actions`) — [actions.md](actions.md);
- `access`, `userModel` — [access.md](access.md);
- `config` list-действия — [list.md](list.md);
- словари `locators`/`savers`/`removers` — [persistence.md](persistence.md);
- мутаторы — [mutators.md](mutators.md);
- типы — [types.md](types.md);
- секции `constraints` и `types` — [constraints.md](constraints.md).

## Секция `impl`

Структура `impl`:

| Поле               | Тип              | Назначение                                  |
| ------------------ | ---------------- | ------------------------------------------- |
| `models`           | мапа             | Реализация моделей (классы, getter/setter). |
| `actions`          | мапа             | Привязка действий к сервисам.               |
| `locators`         | мапа alias→class | Классы-локаторы.                            |
| `savers`           | мапа alias→class | Классы-сейверы.                             |
| `removers`         | мапа alias→class | Классы-ремуверы.                            |
| `mutators`         | мапа alias→class | Мутаторы атрибутов.                         |
| `relationMutators` | мапа alias→class | Мутаторы связей.                            |
| `userModel`        | string           | Тип модели пользователя.                    |
| `defaultLocator`   | string           | Локатор для моделей без своего (см. [models.md](models.md), «Локатор модели»). |

## Генерация конфига под задачу

Рекомендованный порядок при написании нового конфига:

1. **Определите модели.** Для каждой задайте `schema.models.<type>` и
   соответствующий `impl.models.<type>` с `class`. Каждому типу нужен атрибут
   `id` с рабочим getter в impl.
2. **Опишите атрибуты.** В schema — `type` (+ `writable`, если запись
   ограничена). В impl — обязательно заведите одноимённую запись для **каждого**
   атрибута, внутри неё укажите getter/setter/constructorParam. Если у класса
   публичные свойства с теми же именами, сами `getter`/`setter` можно опускать
   (автоопределение), но запись атрибута в impl пропускать нельзя.
3. **Опишите связи.** В schema — `type` и `targetModel` (целевой тип обязан
   существовать среди моделей). В impl — заведите одноимённую запись для каждой
   связи; getter/setter/add внутри неё указывайте при необходимости.
4. **Опишите действия.** В `schema.actions.<model>` перечислите нужные
   `create/read/list/update/delete`. Добавьте `access`, `writable`, `include`,
   `includable`, `config` по потребности. В `impl.actions.<model>` привяжите
   `entityLocator/entitySaver/entityRemover` и (для list) `config.class` /
   `config.repository`.
5. **Объявите сервисы.** Каждый использованный алиас locator/saver/remover должен
   быть в соответствующем словаре `impl`, и наоборот — лишних (неиспользуемых)
   быть не должно.
6. **Добавьте мутаторы** (если нужны): объявление по фазам в schema + классы в
   impl.
7. **Укажите `userModel`** (если в системе есть авторизация по ролям).
8. **Добавьте ограничения** (если нужны): секция `constraints` — правила на
   атрибуты/связи, с разбиением на базовые и контекстные (create/update);
   переиспользуемые наборы значений — секция `types`. Только правила, замкнутые
   на схему (тип + литералы); правила с `this`/окружением — атрибутами на
   классах. После правки — перезапуск прибилда, сгенерированный артефакт руками
   не правится. См. [constraints.md](constraints.md).

Парность ключей schema↔impl строгая в **обе** стороны: любой impl-атрибут/связь/
модель/действие обязан иметь пару в schema (иначе — «осиротевший impl»), и любой
schema-атрибут/связь/модель обязан иметь пару в impl (иначе — «неполный impl»).
Действия в impl необязательны: действие может быть описано только в schema (тогда
`entityLocator/entitySaver/entityRemover` остаются `null`). Подробности и тексты
ошибок — в разделах «осиротевшие impl» и «Полнота impl относительно schema».

## Валидация конфига

Ниже — что именно проверяется и при каком условии падает загрузка. Удобно
использовать как чек-лист при ревью готового конфига. Все ошибки —
`InvalidConfigStructureException` либо `MissingRequiredFieldException`, если иное
не указано. Обе относятся к категории `FrameConfigurationException` (500).

### Структура и обязательные поля

- Корень не объект / список / скаляр → `Root element must be an object`.
- Нет `schema` или `impl`, либо они не объекты → `Field 'schema'/'impl' must be an object`.
- Атрибут без `type` → отсутствует обязательное поле `type`.
- Связь без `type` или `targetModel` → отсутствует обязательное поле.
- impl-модель без `class` → отсутствует обязательное поле `class`.
- Мапа подана списком (или наоборот) → `... must be an object, not an array` /
  `... must be a list`.
- Неверный тип действия → `Invalid action type '...'. Allowed values: create, read, list, update, delete`.
- `includable` подан списком → `Field '...includable' must be an object or null`.
- коллекция в `writable` update-действия с `setter: false` → `Collection relation '...' is writable for update on model '...' but has 'setter: false': update replaces the whole collection, declare a setter or remove the path from 'writable'`.
- вложенный путь `writable` без родителя (`"author.address": create` без `author`) → `Writable path 'author.address' of action '...' on model '...' requires its parent 'author' to be declared in 'writable' too`.

### Согласованность schema ↔ impl (до merge)

- **Осиротевший impl**: impl-модель/атрибут/связь/действие без пары в schema →
  `impl.models[...]... has no corresponding ... in schema`.
- **Полнота impl относительно schema** (`validateNoMissingImpl`): schema-модель/
  атрибут/связь без пары в impl. Проверяется до слияния, поэтому неполный impl
  даёт внятную валидационную ошибку, а не `Error` из `Merger`:
  - модель есть в schema, но нет в impl →
    `schema.models[<type>] has no corresponding model in impl`;
  - атрибут есть в schema-модели, но нет в impl-модели →
    `schema.models[<type>].attributes[<name>] has no corresponding attribute in impl`;
  - связь есть в schema-модели, но нет в impl-модели →
    `schema.models[<type>].relations[<name>] has no corresponding relation in impl`.
    Действия не проверяются: их можно описывать только в schema.
- **writeMode ↔ impl** (`validateSchemaImplConsistency`):
  - `writable:false` (ReadOnly), но impl задаёт setter → `... declares a setter but schema writeMode is 'read-only'`.
  - `writable:false`, но impl задаёт `constructorParam:true` → `... declares constructorParam=true but schema writeMode is 'read-only'`.
  - `writable:"create-only"`, но impl задаёт setter → `... declares a setter but schema writeMode is 'create-only'`.
  - `writable:true` (Write), но impl задаёт `constructorParam:true` → `... declares constructorParam=true but schema writeMode is 'write' (use 'create-only' instead)`.

### Секция constraints (при наличии)

Проверяется прибилдом; в рабочий конфиг секция не попадает.

- Ссылка на несуществующую модель / атрибут / связь →
  `constraints: unknown model "..."` / `...: unknown attribute` / `...: unknown relation`.
- Правило неприменимо к типу свойства (матрица применимости, включая
  разрешение именованных типов из `types` до их kind) →
  `...: rule "..." is not applicable to this type` (с указанием группы:
  `(rules)`, `(create)`, `(update)`, `(each)`).
- `each` на не-list атрибуте → `"each" is only allowed on list types`;
  `each` на связи → `"each" is not allowed on relations`.
- Отсутствует обязательный параметр правила → `rule "..." requires param "..."`.

Ошибки собираются все сразу, а не по первой.

### impl-словари

- locators/savers/removers/mutators/relationMutators поданы списком →
  `impl.<dict> must be an associative array, not a list`.
- Пустой ключ → `impl.<dict> key must be a non-empty string`.
- Пустое значение → `impl.<dict>[...] value must be a non-empty string class name`.

### Пост-валидации (после merge)

- **id моделей**: модель без атрибута `id` со строковым непустым getter →
  `Model '<type>' must define an attribute 'id' with a getter`. Для `id` getter
  обязателен **строкой**: автоопределение по публичному свойству на `id` **не**
  распространяется. `getter: null` (отсутствие) и `getter: false` для `id`
  невалидны — нужен явный строковый метод/свойство (например `"getter": "getId"`).
- **Булевы фильтры**: для list-фильтра по полю типа `bool`/`boolean` любой
  оператор кроме `equal` → `Boolean filter '...' in model '...' supports only 'Equal' operator, '...' given`.
  Тип поля определяется так же, как в рантайме: если у фильтра задан `typeSpec` —
  по нему (bool ⇔ `typeSpec` приводится к `bool`), атрибут модели не учитывается;
  иначе — по типу одноимённого атрибута модели.
- **Фильтры list-действий** (`validateListFilters`, только для действий с
  непустым `config.class`) — согласованность `config.filters` со схемой и
  сгенерированными DTO-классами:
  - list-действие ссылается на неизвестную модель →
    `List action refers to unknown model '...'`;
  - правило без операторов →
    `Filter rule '...' of model '...' must declare at least one operator`;
  - путь правила не резолвится в атрибут схемы (через цепочку реляций) и
    правило не имеет `typeSpec` →
    `Filter rule '...' of model '...' does not resolve to a schema attribute and has no typeSpec`;
  - объявлены правила, но Filter-класс отсутствует либо у корневого класса нет
    свойства `filter` →
    `Model '...' declares filter rules but filter class '...' is missing or root class '...' has no 'filter' property`;
  - оператор правила без сгенерированного класса →
    `Filter rule '...' of model '...' declares operator '...' but class '...' is missing`;
  - у Filter-класса нет свойства под оператор →
    `Filter class '...' has no property '...' for operator '...' declared by rule '...' of model '...'`;
  - путь правила без свойства в классе оператора (конфиг декларирует
    несуществующую возможность) →
    `Filter rule '...' of model '...' has no matching property '...' in class '...'`;
  - правило по связи объявляет оператор кроме `equal`/`in` →
    `Filter rule '...' of model '...' resolves to a relation and supports only 'equal', 'in' operators, '...' given`;
  - у целевой модели правила-связи нет локатора (см. [models.md](models.md),
    «Локатор модели») →
    `Filter rule '...' of model '...' resolves to relation target '...' which has no entity locator. Set impl.models[...].entityLocator, a read action with entityLocator or impl.defaultLocator`;
  - обратный дрейф — публичное свойство класса оператора без покрывающего
    правила (после перехода на вайтлист оно нефильтруемо, мёртвый код) →
    `Property '...' of class '...' has no corresponding rule in 'filters' of model '...' list action`.

  Проверки резолва пути и непустых операторов от PHP-классов не зависят и
  работают всегда; проверки, требующие рефлексии над сгенерированными классами,
  пропускаются, пока `config.class` не существует (генератор классов сам
  загружает конфиг — жёсткое требование сломало бы первую генерацию). После
  генерации следующая загрузка конфига валидирует полностью. Свойства классов
  читаются функциями PHP (`property_exists`, `get_object_vars` на свежем
  инстансе), Reflection API не используется.

- **Целевые модели связей**: связь ссылается на несуществующий `targetModel` →
  `Relation '...' in model '...' refers to unknown target model '...'`.
- **Locators/Savers/Removers**:
  - пустой алиас → `Invalid 'entityLocator|entitySaver|entityRemover' for action '...' on model '...': must be a non-empty string`
    (если конфиг собран в обход загрузчика, в рантайме то же даёт
    `ActionAliasNotConfiguredException` — ошибку конфигурации → 500);
  - алиас не объявлен в словаре → `Action '...' on model '...' references undefined locator|saver|remover '...'`;
  - объявлен, но не используется → `Locator|Saver|Remover '...' is declared but not used by any action`.
- **Mutators / RelationMutators**:
  - класс не существует → `... refers to undefined class '...'`;
  - класс не реализует нужный интерфейс → `... must implement AttributeMutatorInterface|RelationMutatorInterface`;
  - `mutatorSetter` без объявленных мутаторов → `... declares mutatorSetter but has no mutators`;
  - есть мутаторы, но нет валидного setter/mutatorSetter → `... uses mutators but has no valid setter or mutatorSetter` (для связи: `... has setter explicitly disabled and no mutatorSetter`);
  - есть мутаторы при `constructorParam:true` → `... uses mutators but is defined as constructorParam`;
  - ссылка на необъявленный алиас → `... references undefined mutator alias '...'`;
  - объявленный, но неиспользуемый алиас → `... is declared but not used by any ...`.
- **userModel**: тип отсутствует среди моделей → `UserModel '...' does not exist in the models list`; класс не реализует `UserInterface` → `User class '...' must implement UserInterface`.
- **repository** (из `impl.actions.<model>.list.config.repository`): класс не
  существует → `Repository class '...' ... does not exist`; не реализует
  `EntityCollectionInterface` → `... must implement EntityCollectionInterface`.

> Часть пост-валидаций (mutators-классы, userModel, repository) опирается на
> `class_exists`/`class_implements`, то есть требует, чтобы соответствующие
> PHP-классы были загружаемы в момент разбора конфига.

## Ошибки парсинга

| Загрузчик          | Ошибка чтения файла                                  | Ошибка парсинга                                      | Корень не объект                                                     |
| ------------------ | ---------------------------------------------------- | ---------------------------------------------------- | -------------------------------------------------------------------- |
| `JsonConfigLoader` | `InvalidJsonException` (`Unable to read file '...'`) | `InvalidJsonException` (`Failed to parse JSON: ...`) | `InvalidConfigStructureException` (`Root element must be an object`) |
| `YamlConfigLoader` | `InvalidYamlException` (`Unable to read file '...'`) | `InvalidYamlException` (`Failed to parse YAML: ...`) | `InvalidConfigStructureException` (`Root element must be an object`) |

Семантика разбора (структура, валидации, merge) после успешного парсинга — общая.
`YamlConfigLoader` поставляется Symfony-бандлом
(`UniMethod\SymfonyLib\Config\InvalidYamlException`). Все исключения таблицы —
категории `FrameConfigurationException`.

> Это ошибки **этапа загрузки конфига**: они падают там, где приложение
> загружает конфиг, — при старте или, если конфиг создаётся лениво (как в
> Symfony-бандле), при загрузке роутов и на первом запросе. В последнем случае
> они долетают до границы приложения вместе с рантайм-ошибками, и граница
> отличает их по маркеру. Рантайм-ошибки запроса фреймворк делит на доменные
> (4xx, показываются клиенту), ошибки конфигурации и прочие сбои (обе — 5xx, на
> границу). Модель ошибок, коды и маркеры `FrameDomainException` /
> `FrameConfigurationException` описаны в
> [errors.md](errors.md).

## Заметки по YAML

- Корень — мапа (не список). Список на верхнем уровне → `Root element must be an object`.
- `null` пишется как `~` или пустое значение; пустая мапа — `{}`, пустой
  список — `[]`.
- `getter: false` / `writable: false` — это булевы значения, что и нужно
  (`false` = не использовать / ReadOnly). Для автоопределения поле `getter`/
  `setter` опускают, но саму запись атрибута/связи в impl оставляют (полностью
  опустить её нельзя — см. «Полнота impl относительно schema»).
- Имена классов с обратными слешами берите в **одинарные** кавычки
  (`'App\Entity\User'`), иначе `\` может быть истолкован как escape.
- `create-only` — обычная строка, кавычки не обязательны.
- Режимы связей (`reference`, `create`, `reference_or_create`) — тоже обычные
  строки без кавычек.
