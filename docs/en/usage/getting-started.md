# Getting started

How to assemble `ApiService` and what requests to it look like.

## Assembling ApiService

The final step is to assemble `ApiService` with all your implementations and call
`execute` for each incoming request.

The mappers themselves are created before that and receive `Config` as the **last constructor
argument**; `MappingToListAction` takes the locator container
(`ListLocators`) as its second argument — it is needed by filters by a whole relation, which load
the entity by the passed id:

```php
$mappingToListAction = new MappingToListAction($typeSpecParser, $locators, $caster, $config);
$mappingToObject     = new MappingToObject($treeBuilder, $typeSpecParser, $caster, $locators, $accessor, $config);
$mappingFromObject   = new MappingFromObject($mutatorRegistry, $typeSpecParser, $caster, $config);
$actionValidator     = new ActionValidator($treeBuilder, $config);
$mutatorRegistry     = new MutatorRegistry($mutators, $accessor, $relationMutators, $config);
```

`setConfig($config)` is kept as an alternative path (when DI creates the service
earlier than the config), but the config is set **exactly once**: a second call —
`ConfigAlreadySetException`. Calling a service without a config —
`ConfigNotSetException` (or `ListActionMisconfiguredException` for
`MappingToListAction`). There is no request state inside the services: the locator
alias of the current action is passed as an argument, property caches are built per
class via Reflection — services can be kept as singletons in a long-lived
worker (Swoole/RoadRunner/FrankenPHP).

```php
$api = new ApiService(
    constraintChecker:   $constraintChecker,    // ConstraintCheckerInterface
    config:              $config,                // loaded by a config loader
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
    userProvider:        $userProvider,          // or null if access control is not needed
    repositories:        $repositories,          // iterable<EntityCollectionInterface>
    domainExceptionObserver: $observer,          // or null
);

$result = $api->execute(
    modelType:  'user',
    actionType: ActionType::Read,
    queryParams: $query,
    rawContent:  $rawBody,   // request body (for create/update)
    id:          $id,        // for read/update/delete
);
```

`execute` returns an `ApiResult` (`statusCode`, `data`, `headers`, `encoded` —
`data` is already serialized) —
serialize it into an HTTP response at your entry point. All services receive one and
the same `Config` (see above): components assembled with different configs give
`ActionNotConfiguredException`.

## Relations: `writable` / `include` / `includable` / `?included=`

- `writable` (config) — input: which relations the client can pass and in which mode.
- `?included=` on create/update — the input declaration; the body must match it (an undeclared relation in the body → 422, a declared one without a body → 422).
- `include` (config) — output: relations that are always present in the response.
- `includable` (config) — output: what the client can add via `?included=`.

```
POST /post?included=author
{ "title": "T", "author": { "id": 5 } }
```

Details — [domains/actions.md](../domains/actions.md), "writable, include, includable and the `included` parameter".

## List control

### Pagination
```
?page[number]=1&page[size]=20
```

### Sorting
```
?sort[name]=asc&sort[createdAt]=desc&sort[address.city]=asc
```

### Filter
```
?filter[equal][id]=123
&filter[equal][telegramId]=456
&filter[in][telegramId][]=1&filter[in][telegramId][]=2
&filter[range][createdAt][0]=2024-01-01&filter[range][createdAt][1]=2024-12-31
&filter[like][userName]=john
&filter[equal][relation.city.name]=Paris
```

Details — [list-action.md](list-action.md).

### Example with business logic

```shell
User (id)
   ↑
Purchase (user_id, post_id)
   ↑
Post (id)
```

All posts
```shell
GET /post
```

Posts purchased by the user
```shell
GET /post?filter[equal][purchases.user.id]=123
```
