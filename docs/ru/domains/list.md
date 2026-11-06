# list-запросы

Справочник по list-действию: правила в конфиге и генерируемые DTO. Пошаговое добавление list-действия — [usage/list-action.md](../usage/list-action.md).

## `config` list-действия

`config` присутствует только у действия `list` и описывает три блока
(**других ключей в schema-секции быть не может** — неизвестный ключ, включая
опечатку вроде `filtres`, отвергается на загрузке; исключение — легаси-запись
`class: null` / `repository: null`, которая допускается и игнорируется, тогда
как непустые `class`/`repository` в schema-секции — ошибка «must be set in the
impl section»):

- `pagination` — `{ maxSize?: int|null, defaultSize?: int }`. Если блок задан, но
  `defaultSize` опущен — по умолчанию `20`. Если опущен `maxSize` — по умолчанию
  `100` (значение `PaginationRule::DEFAULT_MAX_SIZE`, совпадает с дефолтом
  конструктора). Чтобы снять верхнюю границу размера страницы, нужно **явно**
  указать `maxSize: null` — это осознанное действие, отсутствие ключа лимит не
  снимает.
- `sort` — список правил сортировки. Как и `filters`, это **вайтлист**: поле
  `sort[<path>]` вне списка или направление не `asc`/`desc` → 400
  `InvalidListQueryException`; пустой список означает, что сортировка клиенту
  недоступна.
- `filters` — список правил фильтрации. Это **вайтлист и единственный источник
  истины**: в рантайме поле применяется только если путь заявлен здесь и
  оператор входит в `operators` правила; незаявленный путь, оператор вне
  правила или неизвестный ключ оператора → 400 `InvalidListQueryException`.
  Заявленный путь, который не резолвится в схему и не имеет `typeSpec`, —
  ошибка загрузки конфига (а если такой конфиг всё же дожил до рантайма —
  500 `ListActionMisconfiguredException`).

Правило сортировки (`sort[]`):

| Поле               | Тип                      | Назначение                                                   |
| ------------------ | ------------------------ | ------------------------------------------------------------ |
| `path`             | string (**обязательно**) | Путь к полю (`address.city`).                                |
| `defaultDirection` | `asc`/`desc`             | Направление, если клиент не указал.                          |
| `required`         | bool                     | Обязательность сортировки по полю.                           |
| `defaultValue`     | `{ path, direction }`    | Сортировка по умолчанию, если клиент не передал sort вообще. |
| `comment`          | string                   | Комментарий.                                                 |

Правило фильтрации (`filters[]`):

| Поле           | Тип                      | Назначение                                                                                                                                          |
| -------------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `path`         | string (**обязательно**) | Путь к полю.                                                                                                                                        |
| `operators`    | список (**обязательно**) | Операторы (см. ниже).                                                                                                                               |
| `required`     | bool                     | Обязателен ли фильтр.                                                                                                                               |
| `defaultValue` | `{ operator, value }`    | Значение фильтра по умолчанию.                                                                                                                      |
| `comment`      | string                   | Комментарий.                                                                                                                                        |
| `typeSpec`     | string                   | Кастомный тип поля; если задан, атрибут модели не ищется. **Обязателен**, если `path` не резолвится в атрибут схемы (виртуальное поле репозитория). |

Оператор в `operators` — либо строка (`equal`, `in`, `like`, `range`), либо
объект `{ operator, access? }` для ограничения доступа к конкретному оператору.

**Что из этого применяет фреймворк, а что — вы.** В рантайме либа использует
только `filters[].path`/`operators`/`required`/`typeSpec` (вайтлист и приведение
типов в `MappingToListAction`). `pagination`, `sort[].defaultDirection`,
`sort[].required`, `sort[].defaultValue` и `filters[].defaultValue` она **не
применяет** — они доступны потребителю через `Config` (генератор DTO кладёт
дефолты в инициализаторы свойств, репозиторий клампит `size` по `maxSize`). На
bootstrap `ConfigValidator::validateListRules` проверяет только их
согласованность:

- `pagination`: `maxSize` > 0 или `null`, `defaultSize` > 0,
  `defaultSize <= maxSize`;
- `sort[]`: `path` и `defaultValue.path` резолвятся в атрибут (через цепочку
  связей), пути уникальны, `defaultDirection` и `defaultValue` взаимоисключающи,
  `required` и `defaultValue` взаимоисключающи;
- `filters[]`: пути уникальны, `required` и `defaultValue` взаимоисключающи,
  `defaultValue.operator` входит в `operators`, форма значения соответствует
  оператору (`in` — список, `range` — список из двух, прочие — скаляр), и если
  тип поля известен (`typeSpec` или атрибут модели) — значение приводится к нему
  тем же `Caster`, что и запросы (для `datetime` — только абсолютный ISO 8601).

Нарушение — `InvalidConfigStructureException` с путём до поля.

### Фильтр по связи целиком

`path` правила может указывать не только на атрибут, но и на **связь целиком**
(`user`, `owner.office`). Клиент передаёт id целевой модели, а в операторный DTO
попадает уже загруженная сущность:

```json
{ "path": "user", "operators": ["equal"] }
```

```yaml
- { path: user, operators: [equal] }
```

- Значение из query разбирается как id целевой модели: тип берётся из её
  атрибута `id`; `typeSpec` в правиле переопределяет его так же, как для
  скалярных путей.
- Сущность загружается локатором целевой модели — своим
  (`impl.models.<targetModel>.entityLocator`), read-действия или
  `impl.defaultLocator` (см. [models.md](models.md), «Локатор модели»); для `in` — одним
  `findMany()`, если локатор реализует `EntityBatchLocatorInterface`. Ненайденный
  id — заведомо пустая выборка (200, `[]`, репозиторий не вызывается), см.
  [usage/list-action.md](../usage/list-action.md).
- Допустимы только операторы `equal` и `in` (для `in` — массив id → массив
  сущностей). `like`/`range` на связи — ошибка загрузки конфига.
- Свойство операторного DTO типизировано entity-классом целевой модели
  (`impl.models[<targetModel>].class`) и именуется по общему правилу путей:
  односегментный путь как есть (`user` → `user`), путь с точкой — в rel-форме
  (`owner.office` → `relOwnerRelOffice`). См. «list-действия:
  генерируемые DTO и их именование» ниже.

Правило по связи и правило по её полю независимы и могут сосуществовать:
`path: user` (`filter[equal][user]=123`) даёт свойство `user` типа `?User`,
`path: user.id` (`filter[equal][user][id]=123`) — свойство `relUserRelId` типа
`?int`.

```json
{
  "schema": {
    "models": {
      "product": {
        "attributes": {
          "id": { "type": "int" },
          "name": { "type": "string" },
          "active": { "type": "bool" }
        }
      }
    },
    "actions": {
      "product": {
        "list": {
          "config": {
            "pagination": { "maxSize": 100, "defaultSize": 20 },
            "sort": [
              { "path": "name", "defaultDirection": "asc" },
              {
                "path": "createdAt",
                "defaultValue": { "path": "createdAt", "direction": "desc" }
              }
            ],
            "filters": [
              { "path": "name", "operators": ["equal", "like"] },
              { "path": "active", "operators": ["equal"] },
              {
                "path": "categoryId",
                "operators": [
                  { "operator": "in", "access": { "authRequired": true } }
                ],
                "typeSpec": "int[]"
              }
            ]
          }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    product:
      attributes:
        id:
          type: int
        name:
          type: string
        active:
          type: bool
  actions:
    product:
      list:
        config:
          pagination:
            maxSize: 100
            defaultSize: 20
          sort:
            - path: name
              defaultDirection: asc
            - path: createdAt
              defaultValue:
                path: createdAt
                direction: desc
          filters:
            - path: name
              operators: [equal, like]
            - path: active
              operators: [equal]
            - path: categoryId
              operators:
                - operator: in
                  access:
                    authRequired: true
              typeSpec: "int[]"
```

> Для булевых полей допустим только оператор `equal` — см. [config.md](config.md), «Валидация конфига».

## list-действия: генерируемые DTO и их именование

Для list-действий фреймворк собирает из query-параметров набор DTO и передаёт их
в ваш `EntityCollectionInterface::list`. Сами классы DTO **генерируются** по
конфигу — вы их не пишете, но имена подчиняются строгим правилам, и знание этих
правил нужно, чтобы понимать поведение и писать валидацию.

По базовому имени модели (`{Model}`) генерируются:

- `{Model}Filter` — контейнер операторов фильтрации (свойство `filter` в корневом
  объекте запроса).
- `{Model}Sort` — сортировка (свойство `sort`).
- `{Model}Pagination` — пагинация (свойство `pagination`).
- классы операторов `{Model}{Operator}` — `{Model}Equal`, `{Model}In`,
  `{Model}Range`, `{Model}Like` и т.п. Каждый несёт поля, по которым разрешён
  этот оператор. Набор публичных свойств обязан один-к-одному соответствовать
  правилам `config.filters`: свойство без покрывающего правила и правило без
  свойства — ошибка bootstrap-валидации конфига
  (`ConfigValidator::validateListFilters`), а не тихий пропуск в рантайме.

Имена свойств в операторных DTO:

- локальное поле — как есть (`name` → свойство `name`);
- поле по связи — путь через точку кодируется как
  `rel{Segment}Rel{Segment}...` в PascalCase: `relation.city.name` →
  `relRelationRelCityRelName`.
- связь целиком — имя по тому же правилу, что и для атрибутов (`user` →
  `user`, `owner.office` → `relOwnerRelOffice`). Свойство типизировано
  **entity-классом целевой модели** (`?User`), а не скаляром: фреймворк
  разбирает значение как id, грузит сущность локатором целевой модели (см.
  [models.md](models.md), «Локатор модели») и кладёт объект в DTO до валидации. Ненайденный id — доменное
  `EntityNotFoundException` (404).

Пагинация читает `page[number]` и `page[size]`; сортировка — направление `asc`/
`desc` на поле; фильтры — `filter[<operator>][<field>] = value` (для `in`/`range`
значение — массив). Для правила по связи целиком значение — id целевой модели
(`filter[equal][user]=123`), для `in` — массив id.

DTO операторов могут наследовать `TouchableDto`, чтобы отличать «поле не передано»
от «передано со значением null»:

```php
abstract class TouchableDto
{
    public function markAsSet(string $name): void;
    public function isSet(string $name): bool;
}
```

Фреймворк вызывает `markAsSet` при заполнении поля из query; в своей валидации
(фаза `ListQuery` через `ConstraintCheckerInterface`) используйте `isSet`, чтобы валидировать только
реально переданные поля и корректно обрабатывать required-фильтры.

### Корневой ListRequest (обязателен при любом фильтре/сортировке/пагинации)

Если у list-действия в конфиге задан хотя бы один `filter`, `sort` или
`pagination`, вы **обязаны** создать рукописный корневой класс `{Model}ListRequest`
и указать его в `impl.actions.<model>.list.config.class`. Без него фреймворк не
свяжет query-параметры с DTO, и `$filter`/`$sort`/`$pagination` придут в
`list()`/`countForList()` как `null` — сортировка и пагинация молча не работают,
а объявленные `filters` без Filter-класса (или без свойства `filter` у корня)
не пройдут bootstrap-валидацию конфига.

Класс агрегирует три опциональных поля; имена полей фиксированы (`filter`, `sort`,
`pagination`) — по ним фреймворк раскладывает `filter[...]`, `sort[...]`,
`page[...]`:

```php
class {Model}ListRequest
{
    #[Assert\Valid]
    public ?{Model}Filter $filter = null;

    #[Assert\Valid]
    public ?{Model}Sort $sort = null;

    #[Assert\Valid]
    public ?{Model}Pagination $pagination = null;
}
```

Включайте только те поля, что реально объявлены в конфиге для этого действия.

**Чеклист list-действия:** при добавлении `filters`/`sort`/`pagination` в конфиг
в том же изменении создайте `{Model}ListRequest` (корневой), `{Model}Filter` +
классы операторов под каждый оператор, `{Model}Sort`, `{Model}Pagination`, и
пропишите `config.class` + `config.repository`.
