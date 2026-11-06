# Действия

## Действия (schema-часть)

`schema.actions` — мапа `тип_модели → { тип_действия → описание }`. Тип действия
один из: `create`, `read`, `list`, `update`, `delete`. Любое другое значение —
ошибка `Invalid action type '...'. Allowed values: create, read, list, update, delete`.

Поля описания действия (все необязательны):

| Поле         | Применимость              | Назначение                               |
| ------------ | ------------------------- | ---------------------------------------- |
| `access`     | любое                     | Ограничения по авторизации/ролям.        |
| `writable`   | create/update             | Вход: связи, которые клиент может задекларировать в `?included=` и передать в теле. |
| `include`    | любое                     | Выход: связи, всегда присутствующие в ответе. |
| `includable` | любое                     | Выход: что клиент может добавить к ответу через `?included=`. |
| `config`     | только list               | Пагинация/сортировка/фильтры.            |
| `comment`    | любое                     | Комментарий.                             |

```json
{
  "schema": {
    "models": { "user": { "attributes": { "id": { "type": "int" } } } },
    "actions": {
      "user": {
        "create": {},
        "read": {},
        "update": {},
        "delete": { "comment": "Удаление пользователя" }
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
  actions:
    user:
      create: {}
      read: {}
      update: {}
      delete:
        comment: Удаление пользователя
```

## writable, include, includable и параметр `included`

Это единственное место, где контракт связей описан целиком; остальные документы
ссылаются сюда. Три поля конфига и один query-параметр, у каждого ровно одна
роль:

| Что          | Где            | Роль                                                                 |
| ------------ | -------------- | -------------------------------------------------------------------- |
| `writable`   | конфиг действия | **Вход.** Какие связи клиент *имеет право* передать и в каком режиме. |
| `include`    | конфиг действия | **Выход.** Связи, которые всегда есть в ответе.                      |
| `includable` | конфиг действия | **Выход.** Что клиент может *добавить* к ответу через `?included=`.  |
| `?included=` | query-запроса   | На create/update — **декларация входа**; на любом действии — запрос дополнительной выдачи. |

Параметр называется `included` (не `include`) и принимает строку с запятыми
или массив: `?included=author,items.product` ≡ `?included[]=author&included[]=items.product`.
Любое другое значение — `400 Parameter 'included' must be a string or a list of strings.`

### Запись: контракт, потом тело

На create/update клиент сначала **декларирует** в `?included=`, какие связи он
передаёт, затем присылает тело. Фреймворк проверяет три вещи, все — до
маппинга и до обращения к БД:

1. **Декларация ⊂ `writable`.** Путь из `?included=`, которого нет в `writable`
   (точное совпадение, см. ниже) →
   `422 Relation '<path>' for '<model>' model is not writable for action '<action>'.`
2. **Тело ⊂ декларация.** Связь присутствует в теле (с любым значением, включая
   `null` и `[]`), но не задекларирована →
   `422 Relation '<path>' of model '<model>' is present in the request body but not declared in 'included'.`
3. **Декларация ⊂ тело.** Путь задекларирован, но в теле его нет →
   `422 Relation '<path>' of model '<model>' is declared in 'included' but missing in the request body.`
   Декларация — обязательство, а не разрешение. Задекларировать `author.address`
   и прислать `author: null` — тоже ошибка: вложенная декларация под `null`
   невыполнима.

Пишется ровно **декларация ∩ `writable`**, режим берётся из `writable`.
Атрибутов это не касается: они передаются в теле без декларации.

```
writable: { author: create, "author.address": create, tags: reference }

POST /post?included=author,author.address
{ "title": "T", "author": { "name": "A", "address": { "city": "X" } } }
→ 201, создан автор с адресом

POST /post
{ "title": "T", "author": { "name": "A" } }
→ 422 … 'author' … not declared in 'included'

POST /post?included=author,tags
{ "title": "T", "author": { "name": "A" } }
→ 422 … 'tags' … declared in 'included' but missing in the request body

PATCH /post/1?included=author
{ "author": null }
→ 200, связь отвязана (null — валидное значение под декларацией)
```

### Пути только явные

Ключ `writable` — путь связи в точечной нотации. Режим **не наследуется**:
`writable: { author: create }` разрешает только `author`; чтобы писать
`author.address`, нужна отдельная запись `"author.address": create`. При этом
родитель вложенного пути обязан быть объявлен сам —
`writable: { "author.address": create }` без `author` не загрузится:
`Writable path 'author.address' … requires its parent 'author' to be declared in 'writable' too`.
То же правило действует для декларации в `?included=`.

### Режимы

`writable` принимает путь → режим (`reference`, `create`, `reference_or_create`)
или путь → `{ mode, access? }`. Краткая форма списком — все пути получают
`reference`. `access` внутри, если не задан, наследуется от `access` действия;
проверяется только если связь присутствует в теле (включая `null`), отказ →
`403 Access denied for relation '<path>'.`

Ветка выбирается по форме полезной нагрузки, не по режиму: объект **ровно с
одним свойством `id`** — ссылка (`{ "id": null }` — не ссылка: `422 Reference
'<path>' requires a non-null 'id' field.`; отсутствующая сущность → `404`);
любой другой объект, включая `id` рядом с другими полями, — создание.
`reference` принимает только ссылку, `create` — только создание,
`reference_or_create` — оба. Несовпадение →
`422 Relation '<rel>' in model '<model>' does not allow mode '<mode>' for action '<action>'.`

Ссылка грузится локатором целевой модели — своим, read-действия или
`impl.defaultLocator` (см. [models.md](models.md), «Локатор модели»). Без
локатора — `DtoReferencedException` (500).

Создание вложенной сущности выполняет тот же сейвер, что и корневую: для
doctrine-сейвера нужен `cascade: ['persist']` и `Assert\Valid` на свойстве,
иначе 500 из БД вместо 422.

```json
{
  "schema": {
    "actions": {
      "post": {
        "create": {
          "writable": {
            "tags": "reference",
            "author": { "mode": "create", "access": { "authRequired": true } },
            "author.address": "create"
          }
        }
      }
    }
  }
}
```

```yaml
schema:
  actions:
    post:
      create:
        writable:
          tags: reference
          author:
            mode: create
            access:
              authRequired: true
          author.address: create
```

### Коллекции при update: PATCH

Для связей `*ToMany` на update действует семантика JSON merge-patch:

| В теле                      | Результат                                   |
| --------------------------- | ------------------------------------------- |
| ключа нет                   | коллекция не тронута                        |
| `"tags": [{id:1},{id:2}]`   | коллекция заменена целиком на `[1, 2]`      |
| `"tags": []` или `"tags": null` | коллекция пуста                          |

Дописывания без удаления нет: замена делается через setter, поэтому у связи в
`writable` update-действия setter обязан резолвиться. `setter: false` с одним
`add` — ошибка bootstrap (`Collection relation 'tags' is writable for update …
but has 'setter: false'`); если setter не резолвится в рантайме (автоопределение
по свойству не нашло публичного поля) —
`CollectionReplaceRequiresSetterException` (500). На create `add` по-прежнему
допустим: коллекция наполняется с нуля. Для одиночных связей `null` — отвязать.

### Выдача: `include` и `includable`

- `include` — список путей, которые **всегда** разворачиваются в ответе, без
  query-параметра. Через `includable` и `access` не фильтруется. Это и есть
  способ отдать результат работы сейвера: связь, которую сервер заполняет сам,
  описывается **только** в `include`; в `writable` её добавлять не нужно и
  нельзя — `writable` описывает вход.
- `includable` — мапа `путь → access`: что клиент может **добавить** к выдаче
  через `?included=`. Значение `null` — без дополнительных ограничений
  (действует `access` самого действия); `{ authRequired, roles }` — отдельное
  ограничение. Сравнение путей точное: `includable: { posts: null }` не
  покрывает `posts.tags`. Если свойства `includable` нет — клиент может
  запросить любую связь модели.
- Путь из `?included=`, которого нет в `includable`, или на который нет
  доступа, **молча отбрасывается** из выдачи (не 400 и не 403). На
  create/update это не отменяет проверок входа: тот же путь по-прежнему обязан
  быть в `writable` и в теле.

Состав ответа: `include` ∪ (`?included=` ∩ `includable`). Выдача на записи —
это обычное чтение сохранённого объекта, никаких особых правил.

```json
{
  "schema": {
    "actions": {
      "user": {
        "read": {
          "include": ["profile", "posts.tags"],
          "includable": {
            "profile": null,
            "adminLogs": { "authRequired": true, "roles": ["admin"] }
          }
        }
      }
    }
  }
}
```

```yaml
schema:
  actions:
    user:
      read:
        include: [profile, posts.tags]
        includable:
          profile: ~
          adminLogs:
            authRequired: true
            roles: [admin]
```

> В YAML `null` записывается как `~` или пустое значение.

### Сводка

| Запрос                                      | `writable`      | `include`   | Итог                                              |
| ------------------------------------------- | --------------- | ----------- | ------------------------------------------------- |
| `POST ?included=user` + `{user:{id:1}}`     | `user: reference` | —         | 201, `user` в ответе                              |
| `POST` + `{user:{id:1}}` (без декларации)   | `user: reference` | —         | 422 not declared                                  |
| `POST ?included=user` + `{}`                | `user: reference` | —         | 422 declared but missing                          |
| `POST ?included=token` + `{}`               | —               | `[token]`   | 422 not writable (`token` — не вход)              |
| `POST` + `{}`                               | —               | `[token]`   | 201, `token` в ответе (заполнил сейвер)           |
| `GET ?included=adminLogs` без роли admin    | —               | —           | 200 без `adminLogs`                               |

## Действия (impl-часть)

`impl.actions` — мапа `модель → { действие → { entityLocator?, entitySaver?, entityRemover?, config? } }`.
`config` здесь несёт только PHP-специфику list: `class` (корневой ListRequest-DTO,
агрегирующий filter/sort/pagination) и `repository` (класс репозитория,
реализующий `EntityCollectionInterface`). Параметры пагинации/сортировки/фильтров
живут в `schema`. Других ключей impl-list-конфиг не принимает: любой посторонний
ключ — в том числе `filters`, по ошибке положенный в impl-секцию, — это ошибка
загрузки (`Unknown key '...' ... Allowed keys: class, repository`), а не тихо
игнорируемая запись, как было раньше.

Эти классы — `class` (корневой `{Model}ListRequest`), сопутствующие
`{Origin}Filter`/`{Origin}Sort`/`{Origin}Pagination`, классы операторов и сам
`repository` — вы **пишете руками**. Фреймворк находит и заполняет их по строгим
соглашениям об именах (имя выводится из entity-класса модели, namespace — из
корневого `config.class`), поэтому именование критично. Полные правила именования,
кодировку путей связей и пример репозитория см. в
[list.md](list.md). Здесь, в конфиге, вы указываете лишь
полное имя корневого класса и класса репозитория.

`entityLocator`/`entitySaver`/`entityRemover` — это **алиасы** из словарей
`locators`/`savers`/`removers`. По смыслу: locator — для read/delete, saver —
для create/update, remover — для delete.

```json
{
  "schema": {
    "models": { "user": { "attributes": { "id": { "type": "int" } } } },
    "actions": { "user": { "read": {}, "create": {}, "delete": {} } }
  },
  "impl": {
    "models": {
      "user": {
        "class": "App\\Entity\\User",
        "attributes": { "id": { "getter": "getId" } }
      }
    },
    "actions": {
      "user": {
        "read": { "entityLocator": "doctrine" },
        "create": { "entitySaver": "doctrine" },
        "delete": { "entityLocator": "doctrine", "entityRemover": "doctrine" }
      }
    },
    "locators": { "doctrine": "App\\Locator\\DoctrineLocator" },
    "savers": { "doctrine": "App\\Saver\\DoctrineSaver" },
    "removers": { "doctrine": "App\\Remover\\DoctrineRemover" }
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
  actions:
    user:
      read: {}
      create: {}
      delete: {}
impl:
  models:
    user:
      class: 'App\Entity\User'
      attributes:
        id:
          getter: getId
  actions:
    user:
      read:
        entityLocator: doctrine
      create:
        entitySaver: doctrine
      delete:
        entityLocator: doctrine
        entityRemover: doctrine
  locators:
    doctrine: 'App\Locator\DoctrineLocator'
  savers:
    doctrine: 'App\Saver\DoctrineSaver'
  removers:
    doctrine: 'App\Remover\DoctrineRemover'
```
