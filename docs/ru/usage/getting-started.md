# Начало работы

Как собрать `ApiService` и как выглядят запросы к нему.

## Сборка ApiService

Финальная точка — собрать `ApiService` со всеми вашими реализациями и вызывать
`execute` на каждый входящий запрос.

Сами мапперы создаются до этого и получают `Config` **последним аргументом
конструктора**; `MappingToListAction` принимает вторым аргументом контейнер
локаторов (`ListLocators`) — он нужен фильтрам по связи целиком, которые грузят
сущность по переданному id:

```php
$mappingToListAction = new MappingToListAction($typeSpecParser, $locators, $caster, $config);
$mappingToObject     = new MappingToObject($treeBuilder, $typeSpecParser, $caster, $locators, $accessor, $config);
$mappingFromObject   = new MappingFromObject($mutatorRegistry, $typeSpecParser, $caster, $config);
$actionValidator     = new ActionValidator($treeBuilder, $config);
$mutatorRegistry     = new MutatorRegistry($mutators, $accessor, $relationMutators, $config);
```

`setConfig($config)` сохранён как альтернативный путь (когда DI создаёт сервис
раньше конфига), но конфиг задаётся **ровно один раз**: второй вызов —
`ConfigAlreadySetException`. Вызов сервиса без конфига —
`ConfigNotSetException` (или `ListActionMisconfiguredException` у
`MappingToListAction`). Внутри сервисов нет запросного состояния: алиас
локатора текущего действия передаётся аргументом, кэши свойств строятся по
классу через Reflection — сервисы можно держать синглтонами в долгоживущем
воркере (Swoole/RoadRunner/FrankenPHP).

```php
$api = new ApiService(
    constraintChecker:   $constraintChecker,    // ConstraintCheckerInterface
    config:              $config,                // загружен конфиг-загрузчиком
    treeBuilder:         $treeBuilder,
    convertToStdClass:   $convertToStdClass,
    convertToJson:       $convertToJson,
    mappingFromObject:   $mappingFromObject,
    mappingToObject:     $mappingToObject,
    mappingToListAction: $mappingToListAction,
    actionValidator:     $actionValidator,
    locators:            $locators,              // ListLocators
    savers:              $savers,                // ListSavers
    removers:            $removers,              // ListRemovers
    manipulators:        $mutatorRegistry,       // MutatorRegistry
    userProvider:        $userProvider,          // или null, если доступ не нужен
    repositories:        $repositories,          // iterable<EntityCollectionInterface>
    domainExceptionObserver: $observer,          // или null
);

$result = $api->execute(
    modelType:  'user',
    actionType: ActionType::Read,
    queryParams: $query,
    rawContent:  $rawBody,   // тело запроса (для create/update)
    id:          $id,        // для read/update/delete
);
```

`execute` возвращает `ApiResult` (`statusCode`, `data`, `headers`, `encoded` —
`data` уже сериализована) —
сериализуйте его в HTTP-ответ на своей точке входа. Все сервисы получают один и
тот же `Config` (см. выше): компоненты, собранные с разными конфигами, дают
`ActionNotConfiguredException`.

## Связи: `writable` / `include` / `includable` / `?included=`

- `writable` (конфиг) — вход: какие связи клиент может передать и в каком режиме.
- `?included=` на create/update — декларация входа; тело обязано ей соответствовать (недекларированная связь в теле → 422, задекларированная без тела → 422).
- `include` (конфиг) — выход: связи, всегда присутствующие в ответе.
- `includable` (конфиг) — выход: что клиент может добавить через `?included=`.

```
POST /post?included=author
{ "title": "T", "author": { "id": 5 } }
```

Подробно — [domains/actions.md](../domains/actions.md), «writable, include, includable и параметр `included`».

## Управление списком

### Пагинация
```
?page[number]=1&page[size]=20
```

### Сортировка
```
?sort[name]=asc&sort[createdAt]=desc&sort[address.city]=asc
```

### Фильтр
```
?filter[equal][id]=123
&filter[equal][telegramId]=456
&filter[in][telegramId][]=1&filter[in][telegramId][]=2
&filter[range][createdAt][0]=2024-01-01&filter[range][createdAt][1]=2024-12-31
&filter[like][userName]=john
&filter[equal][relation.city.name]=Paris
```

Подробно — [list-action.md](list-action.md).

### Пример с бизнес логикой

```shell
User (id)
   ↑
Purchase (user_id, post_id)
   ↑
Post (id)
```

Все посты
```shell
GET /post
```

Купленные пользователем посты
```shell
GET /post?filter[equal][purchases.user.id]=123
```
