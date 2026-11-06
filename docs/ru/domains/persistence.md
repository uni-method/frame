# Persistence: locator, saver, remover, repository

Это четыре независимых контракта в `UniMethod\Frame\Persistence`. Какие из них
нужны — зависит от того, какие действия (`read`/`create`/`update`/`delete`/`list`)
описаны в конфиге. Реализации регистрируются в `impl`-словарях
(`locators`/`savers`/`removers`) под алиасами и привязываются к действиям через
`entityLocator`/`entitySaver`/`entityRemover`.

## EntityLocatorInterface — поиск по id (read, update, delete, reference)

```php
interface EntityLocatorInterface
{
    public function find(
        string $className,
        int|string $id,
        array $include = [],
        ?object $object = null,
    ): object|null;
}
```

`find` возвращает сущность или `null`, если её нет. `$include` — список путей
связей для eager-загрузки (`['profile', 'posts.tags']`). `$object` передаётся
только в PATCH-сценарии (Update): сырые данные тела запроса, чтобы при
необходимости доопределить, чем заполнять объект.

Локатор может вызываться несколько раз в рамках одного запроса (разными сервисами),
поэтому имеет смысл свой кэш на время запроса.

Если для существующего id вернуть `null` там, где запись обязана быть (например,
reference-режим связи), фреймворк бросит доменное `EntityNotFoundException` → 404.

Локатор отвечает только на «найти по id». Решение «можно ли текущему пользователю
этот объект» — не его дело: для этого есть фаза `AfterLocate` проверки
ограничений (см. [constraints.md](constraints.md)), которая получает найденный объект и пользователя.
Кастомный локатор ради проверки доступа — антипаттерн.

## EntitySaverInterface — сохранение (create, update)

```php
interface EntitySaverInterface
{
    public function save(object $objectToSave): object;
}
```

`save` принимает уже собранную и провалидированную сущность и возвращает
сохранённую (например, с проставленным id после вставки). Возвращённый объект
идёт в маппинг ответа.

## EntityRemoverInterface — удаление (delete)

```php
interface EntityRemoverInterface
{
    public function remove(object $objectToRemove): void;
}
```

Перед удалением фреймворк сам находит сущность через локатор; в `remove` приходит
уже найденный объект.

## EntityCollectionInterface — список (list)

```php
interface EntityCollectionInterface
{
    public function list(
        object|null $filter = null,
        object|null $sort = null,
        object|null $pagination = null,
    ): array;

    public function countForList(object|null $filter = null): int;
}
```

`$filter`/`$sort`/`$pagination` — это сгенерированные DTO (см. [list.md](list.md)), собранные
фреймворком из query-параметров и провалидированные. `list` возвращает массив
сущностей, `countForList` — общее число записей под тот же фильтр (для заголовка
`X-Total-Count`). Репозиторий list-действия указывается в конфиге list через
`repository` (class-string реализации этого интерфейса) и передаётся в
`ApiService` в наборе `repositories`. Сопоставление — по `instanceof`, а не по
точному имени класса: lazy-прокси и наследники класса из конфига подходят.
Если под один `config.repository` подошло больше одного переданного экземпляра —
`AmbiguousRepositoryException` при конструировании `ApiService`.

## Контейнеры

Реализации складываются в контейнеры `ListLocators`/`ListSavers`/`ListRemovers`
(мапа `alias => реализация`). Обращение по неизвестному алиасу даёт ошибку
конфигурации (`LocatorNotConfiguredException` и т.п., категория
`FrameConfigurationException`) → 500: это ошибка сборки приложения, а не
запроса.

```php
$locators = new ListLocators([
    'doctrine' => new DoctrineUserLocator($em),
]);
```

## Модель как DTO и кастомные сейверы (EntitySaverInterface)

Модель — это **DTO**, а не обязательно Doctrine|Cycle|ActiveRecord-сущность. `schema.models.<type>`
описывает контракт (поля, типы, связи), `impl.models.<type>` привязывает его к
PHP-классу (`class`) и методам доступа. За моделью может стоять ORM-сущность
(`App\Entity\User`), а может — обычный объект-команда (`App\Model\Login`,
`App\Model\ResetUserPassword`): для конфига разницы нет, требования к обоим
одинаковы (атрибут `id` с getter, парность ключей schema↔impl).

**Действие — это операция (`create`/`list`/`update`/...) над моделью, а не
отдельная сущность.** В `schema.actions.<type>` описывается доступ/writable/
include/config, в `impl.actions.<type>` — какой код выполняет операцию
(`entityLocator`/`entitySaver`/...). Один и тот же тип может иметь несколько
действий, и все они работают с одной и той же моделью.

**Вся разница между «обычным» и «кастомным» действием — в одном поле
`entitySaver`.** Если за моделью стоит ORM-сущность и достаточно стандартного
сохранения, указывают `entitySaver: doctrine`. Если нужна доменная логика —
пишут собственный класс (`implements EntitySaverInterface`), регистрируют его в
`impl.savers` под алиасом и подставляют этот алиас в `entitySaver`. Сам класс
сейвера получает на вход объект модели-DTO, выполняет логику, при необходимости
вызывает `flush`, и пишет результат обратно в поля DTO (например `token`).

Типовые признаки модели с кастомным сейвером:

- вход — **DTO-команда**, не ORM-сущность (`App\Model\Login`,
  `App\Model\ChangePassword`, `App\Model\ResetUserPassword`);
- реальная сущность приходит как **связь** через `writable: { user: reference }`
  и декларацию `?included=user`, а не как сама модель; связь, которую сейвер
  только заполняет для ответа, описывается **только** в `include` — в
  `writable` ей не место (см. [actions.md](actions.md), «writable, include, includable и параметр
  `included`»);
- внутри сейвера — доменная логика (проверка пароля, хеширование, запись в
  историю, генерация токена) и явный `flush`;
- результат операции возвращается через поля DTO и `include`.

Пример: модель `resetUserPassword` — DTO с единственной связью `user`. Действие
`create` принимает пользователя по `reference` и делегирует кастомному сейверу,
который выставляет пароль и флаг смены:

```yaml
schema:
  models:
    resetUserPassword:
      attributes:
        id:
          type: "string|null"
          writable: false
      relations:
        user:
          type: oneToOne
          targetModel: user
  actions:
    resetUserPassword:
      create:
        access:
          authRequired: true
          roles: ["ROLE_ADMIN"]
        writable:
          user: reference
        include:
          - user
impl:
  models:
    resetUserPassword:
      class: 'App\Model\ResetUserPassword'
      attributes:
        id:
          getter: getId
      relations:
        user:
          getter: user
          setter: user
  actions:
    resetUserPassword:
      create:
        entitySaver: resetUserPasswordSaver
  savers:
    resetUserPasswordSaver: 'App\Api\Savers\ResetUserPassword'
```

То же самое в JSON:

```json
{
  "schema": {
    "models": {
      "resetUserPassword": {
        "attributes": { "id": { "type": "string|null", "writable": false } },
        "relations": { "user": { "type": "oneToOne", "targetModel": "user" } }
      }
    },
    "actions": {
      "resetUserPassword": {
        "create": {
          "access": { "authRequired": true, "roles": ["ROLE_ADMIN"] },
          "writable": { "user": "reference" },
          "include": ["user"]
        }
      }
    }
  },
  "impl": {
    "models": {
      "resetUserPassword": {
        "class": "App\\Model\\ResetUserPassword",
        "attributes": { "id": { "getter": "getId" } },
        "relations": { "user": { "getter": "user", "setter": "user" } }
      }
    },
    "actions": {
      "resetUserPassword": {
        "create": { "entitySaver": "resetUserPasswordSaver" }
      }
    },
    "savers": {
      "resetUserPasswordSaver": "App\\Api\\Savers\\ResetUserPassword"
    }
  }
}
```

> Связь между алиасом и классом строгая в обе стороны: каждый использованный
> `entitySaver` обязан быть объявлен в `impl.savers`, а каждый объявленный
> сейвер — использоваться хотя бы одним действием (иначе — ошибки
> `references undefined ... saver` / `Saver '...' is declared but not used`,
> см. [config.md](config.md), «Валидация конфига»).
