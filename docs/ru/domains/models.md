# Модели и сущности

Модель описывается в конфиге дважды: в `schema` — контракт (атрибуты, связи), в `impl` — реализация (класс, getter/setter). Реализацию составляет обычный PHP-класс — entity.

## Модель (schema-часть)

```json
{
  "schema": {
    "models": {
      "user": {
        "comment": "Пользователь системы",
        "attributes": { "id": { "type": "int" } },
        "relations": {}
      }
    }
  }
}
```

```yaml
schema:
  models:
    user:
      comment: Пользователь системы
      attributes:
        id:
          type: int
      relations: {}
```

Поля модели: `comment?`, `attributes` (мапа), `relations` (мапа). Все
необязательны, кроме того, что у модели практически всегда должен быть атрибут
`id` (см. [config.md](config.md), «Валидация конфига»). Каждая модель, описанная в schema, обязана иметь
парную запись в `impl.models` — иначе загрузка падает ещё до слияния (см.
«Полнота impl относительно schema»).

## Атрибут (schema-часть)

| Поле       | Тип                                | Назначение                                                                                                                                    |
| ---------- | ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `type`     | string (**обязательно**)           | Тип значения (см. [types.md](types.md)).                                                                                                     |
| `writable` | `true` / `false` / `"create-only"` | Семантика записи.                                                                                                                             |
| `mutators` | объект фаз                         | Мутаторы по фазам.                                                                                                                            |
| `comment`  | string                             | Комментарий. Задаётся **только** в schema — impl-часть поля комментария не несёт, после слияния `Attribute.comment` всегда берётся из schema. |

`writable` управляет тем, как поле ведёт себя при записи (значение
`SchemaWriteMode`):

- `true` → **Write**: поле доступно для записи через setter.
- `false` → **ReadOnly**: setter и `constructorParam` принудительно отключаются.
- `"create-only"` → **CreateOnly**: setter отключается, поле задаётся только
  через конструктор.

Если `writable` не указан — схема не ограничивает запись, и поведение целиком
определяется impl-частью.

```json
{
  "schema": {
    "models": {
      "article": {
        "attributes": {
          "id": { "type": "int" },
          "title": { "type": "string", "writable": true },
          "slug": { "type": "string", "writable": "create-only" },
          "createdAt": { "type": "datetime", "writable": false }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    article:
      attributes:
        id:
          type: int
        title:
          type: string
          writable: true
        slug:
          type: string
          writable: create-only
        createdAt:
          type: datetime
          writable: false
```

## Связь (schema-часть)

| Поле          | Тип                      | Назначение                                                     |
| ------------- | ------------------------ | -------------------------------------------------------------- |
| `type`        | string (**обязательно**) | `oneToOne`, `oneToMany`, `manyToOne`, `manyToMany`.            |
| `targetModel` | string (**обязательно**) | Тип целевой модели.                                            |
| `mutators`    | объект фаз               | Мутаторы по фазам.                                             |
| `comment`     | string                   | Комментарий. Задаётся **только** в schema — impl его не несёт. |

```json
{
  "schema": {
    "models": {
      "order": {
        "attributes": { "id": { "type": "int" } },
        "relations": {
          "customer": { "type": "manyToOne", "targetModel": "customer" },
          "items": { "type": "oneToMany", "targetModel": "order_item" }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    order:
      attributes:
        id:
          type: int
      relations:
        customer:
          type: manyToOne
          targetModel: customer
        items:
          type: oneToMany
          targetModel: order_item
```

## Модель (impl-часть)

`class` обязателен. `attributes`/`relations` — мапы, ключи должны совпадать с
именами из schema. Парность строгая в обе стороны: каждый атрибут и каждая связь
из schema обязаны иметь одноимённую запись в impl (отсутствие — ошибка загрузки,
см. [config.md](config.md), «Полнота impl относительно schema»), и наоборот — лишних impl-записей быть не
должно. Автоопределение getter/setter работает **внутри** присутствующей
impl-записи (когда поля `getter`/`setter` опущены), но саму запись опустить
нельзя.

Поля атрибута (impl):

| Поле               | Значения                             | Смысл                                                                                                  |
| ------------------ | ------------------------------------ | ------------------------------------------------------------------------------------------------------ |
| `getter`           | строка / `false` / отсутствует(null) | Метод/свойство чтения. `false` — не использовать; отсутствие — автоопределение по публичному свойству. |
| `setter`           | строка / `false` / отсутствует(null) | Метод/свойство записи.                                                                                 |
| `mutatorSetter`    | строка / `false` / отсутствует(null) | Метод/свойство записи мутатором.                                                                       |
| `constructorParam` | bool                                 | Передаётся через конструктор.                                                                          |

Поля связи (impl) — те же плюс `add` (метод добавления элемента для `*ToMany`).

Семантика `false` / `null` (отсутствие) едина для всех getter/setter/add/mutatorSetter:

- **строка** — явное имя метода или публичного свойства;
- **`false`** — поле/метод не использовать;
- **отсутствует / `null`** — автоопределение по публичному свойству с тем же
  именем.

Исключение — атрибут `id`: для него getter обязан быть непустой строкой,
автоопределение и `false` недопустимы (см. [config.md](config.md), «Пост-валидации»).

### Локатор модели

Чем модель грузится по id, когда на неё ссылаются: ссылка в `writable`
(`reference`, `reference_or_create`) и фильтр list по связи целиком. Порядок
выбора:

1. `impl.models.<модель>.entityLocator`;
2. `entityLocator` read-действия модели;
3. `impl.defaultLocator` — общий локатор для моделей без своего.

```yaml
impl:
  defaultLocator: doctrine
  models:
    country:
      class: 'App\Entity\Country'
      entityLocator: doctrine    # можно не задавать при defaultLocator
  locators:
    doctrine: 'App\Persistence\DoctrineLocator'
```

Read-действие для этого не нужно: оно только открывает `GET /<модель>/{id}`.
Локаторы update/delete не используются, локатор одной модели для другой не
подставляется. Алиас обязан быть в `impl.locators`, иначе ошибка загрузки
`Field 'impl.models[...].entityLocator' references undefined locator '...'`
(для `defaultLocator` — `Field 'impl.defaultLocator' …`). Если локатора нет ни
одним способом, ссылка на модель — ошибка конфигурации `DtoReferencedException`
(500), фильтр по связи на неё — ошибка загрузки.

`constructorParam` приводится к bool мягко: `true`, строка `"true"`, `1`,
`"1"`, `"yes"` → `true`; всё прочее → `false`.

> **Запись `*ToMany`: setter имеет приоритет над `add`; на update setter
> обязателен** (см. [actions.md](actions.md), «Коллекции при update: PATCH»). При записи коллекции
> маппер сперва резолвит setter; если setter определён (включая
> **автоопределение по публичному свойству** с тем же именем), маппер
> присваивает связи **весь массив целиком через setter**, а `add` при этом не
> вызывается. `add` срабатывает только когда setter явно отключён
> (`setter: false`) или не резолвится. Частая ловушка: коллекция объявлена
> публичным свойством и задан только `add` — автоопределённый setter перебивает
> `add`, и маппер пытается присвоить публичному свойству PHP-массив. Если
> свойство типизировано как объект-коллекция (например
> `Doctrine\Common\Collections\Collection`), присваивание массива упадёт с
> `Cannot assign array to property ... of type Collection`. Решения: либо
> объявить свойство как `array`, либо задать `setter`, принимающий массив и
> наполняющий коллекцию внутри (через свои `add`-вызовы).

> **Чтение `*ToMany`: getter должен возвращать `array`.** При сериализации
> ответа маппер требует, чтобы getter связи `*ToMany` вернул именно PHP-массив
> объектов; иное (в том числе объект-коллекция) приводит к ошибке
> несоответствия типа. Если внутри сущности коллекция хранится как
> `Collection`, задайте getter явным методом, возвращающим массив (например
> `return $this->items->toArray();`), а не полагайтесь на автоопределение по
> свойству. Одиночные связи (`oneToOne`/`manyToOne`) этого не требуют — для них
> ожидается объект или `null`.

```json
{
  "impl": {
    "models": {
      "project": {
        "class": "App\\Entity\\Project",
        "attributes": {
          "id": { "getter": "getId" },
          "name": { "constructorParam": true },
          "server": { "setter": "setServer" },
          "secret": { "getter": false }
        },
        "relations": {
          "items": { "setter": "setItems", "add": "addItem" }
        }
      }
    }
  }
}
```

```yaml
impl:
  models:
    project:
      class: 'App\Entity\Project'
      attributes:
        id:
          getter: getId
        name:
          constructorParam: true
        server:
          setter: setServer
        secret:
          getter: false
      relations:
        items:
          setter: setItems
          add: addItem
```

> В YAML `getter: false` — это булев `false` (= не использовать). Чтобы задать
> автоопределение, поле просто опускают (или пишут `~`).

## Entity-классы

Entity — обычный PHP-класс, на который ссылается `impl.models[<type>].class`.
Фреймворк не требует наследования от своих базовых классов и не навязывает ORM:
важно лишь, чтобы значения можно было читать и писать так, как описано в `impl`.

### Чтение и запись полей

Для каждого атрибута/связи `impl` задаёт `getter`/`setter` (и `add` для коллекций).
Значение каждого из них может быть:

- **строкой** — имя метода или публичного свойства;
- **`false`** — операция отключена (поле не читается/не пишется);
- **опущено / `null`** — автоопределение по публичному свойству с тем же именем.

Резолвинг едины для всех путей (вход, выход, мутаторы): сначала ищется метод с
таким именем, затем публичное свойство. Поэтому оба варианта ниже рабочие.

Через методы:

```php
final class User
{
    private int $id;
    private string $email;

    public function getId(): int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
}
```

Через публичные свойства (getter/setter в `impl` можно опустить — сработает
автоопределение):

```php
final class User
{
    public int $id;
    public string $email;
}
```

### Требование к `id`

У каждой модели практически всегда есть атрибут `id`, и для него `getter`
обязан быть **непустой строкой** — автоопределение и `false` для `id`
недопустимы (это требование валидации конфига, не рантайма). На уровне entity это
значит: предусмотрите явный способ прочитать идентификатор (метод `getId()` или
публичное свойство `id`, указанное строкой в `impl`).

### Конструктор и `constructorParam`

Если атрибут/связь помечены в `impl` как `constructorParam`, фреймворк передаёт
их значения в конструктор **по имени** (именованные аргументы), а не через setter.
Остальные поля выставляются после создания объекта через setter/свойство.

```php
final class Order
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,
    ) {}

    private string $status = 'new';
    public function setStatus(string $s): void { $this->status = $s; }
}
```

Имена параметров конструктора должны совпадать с именами атрибутов/связей из
конфига. Если конструктор бросит ошибку (например, несовместимый тип), фреймворк
обернёт её в `EntityInstantiationException` — ошибку конфигурации (см.
[errors.md](errors.md)): это 500, признак рассогласования entity и конфига.

### Коллекции (`*ToMany`)

Для связей `oneToMany`/`manyToMany` запись возможна через `setter` (принимает
массив целиком) или через `add` (добавляет по одному). Приоритет — у `setter`:
если он задан (в т.ч. автоопределением по публичному свойству), `add` не
используется. Если `setter` отключён (`false`), а `add` задан — фреймворк вызовет
метод `add` для каждого элемента.

```php
final class User
{
    /** @var list<Post> */
    private array $posts = [];

    /** @return list<Post> */
    public function getPosts(): array { return $this->posts; }
    public function addPost(Post $p): void { $this->posts[] = $p; }
}
```

На чтение геттер `*ToMany`-связи может возвращать любой `iterable` — массив или
`Traversable`. Doctrine-коллекция подходит как есть, оборачивать её в
`getPostsAsArray(): array` не нужно:

```php
final class User
{
    /** @var Collection<int, Post> */
    private Collection $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }

    /** @return Collection<int, Post> */
    public function getPosts(): Collection { return $this->posts; }
    public function addPost(Post $p): void { $this->posts->add($p); }
}
```

Скаляр или объект без обхода из такого геттера — рассогласование entity и
конфига: фреймворк ответит ошибкой несоответствия типа. На запись это не
распространяется: `setter` по-прежнему получает массив.
