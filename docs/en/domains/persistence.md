# Persistence: locator, saver, remover, repository

These are four independent contracts in `UniMethod\Frame\Persistence`. Which of them
are needed depends on which actions (`read`/`create`/`update`/`delete`/`list`)
are described in the config. Implementations are registered in the `impl` dictionaries
(`locators`/`savers`/`removers`) under aliases and bound to actions via
`entityLocator`/`entitySaver`/`entityRemover`.

## EntityLocatorInterface — lookup by id (read, update, delete, reference)

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

`find` returns the entity or `null` if it does not exist. `$include` is a list of relation
paths for eager loading (`['profile', 'posts.tags']`). `$object` is passed
only in the PATCH scenario (Update): the raw request body data, so that, if
necessary, it can be determined what to fill the object with.

The locator may be called several times within a single request (by different services),
so it makes sense to have its own cache for the duration of the request.

If `null` is returned for an existing id where the record must exist (for example,
the reference mode of a relation), the framework throws the domain `EntityNotFoundException` → 404.

The locator answers only "find by id". The decision "is the current user allowed
this object" is not its business: for that there is the `AfterLocate` phase of the
constraint check (see [constraints.md](constraints.md)), which receives the found object and the user.
A custom locator for the sake of an access check is an anti-pattern.

## EntitySaverInterface — saving (create, update)

```php
interface EntitySaverInterface
{
    public function save(object $objectToSave): object;
}
```

`save` takes an already assembled and validated entity and returns
the saved one (for example, with the id set after insertion). The returned object
goes into the response mapping.

## EntityRemoverInterface — deletion (delete)

```php
interface EntityRemoverInterface
{
    public function remove(object $objectToRemove): void;
}
```

Before deletion the framework itself finds the entity via the locator; `remove` receives
the already found object.

## EntityCollectionInterface — list (list)

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

`$filter`/`$sort`/`$pagination` are the generated DTOs (see [list.md](list.md)), assembled
by the framework from query parameters and validated. `list` returns an array
of entities, `countForList` — the total number of records for the same filter (for the
`X-Total-Count` header). The repository of a list action is specified in the list config via
`repository` (the class-string of an implementation of this interface) and is passed to
`ApiService` in the `repositories` set. Matching is by `instanceof`, not by
the exact class name: lazy proxies and subclasses of the class from the config are suitable.
If more than one passed instance matches a single `config.repository` —
`AmbiguousRepositoryException` when constructing `ApiService`.

## Containers

Implementations are put into the `ListLocators`/`ListSavers`/`ListRemovers` containers
(a map `alias => implementation`). Accessing an unknown alias gives a configuration
error (`LocatorNotConfiguredException`, etc., category
`FrameConfigurationException`) → 500: this is an application assembly error, not
a request error.

```php
$locators = new ListLocators([
    'doctrine' => new DoctrineUserLocator($em),
]);
```

## A model as a DTO and custom savers (EntitySaverInterface)

A model is a **DTO**, not necessarily a Doctrine|Cycle|ActiveRecord entity. `schema.models.<type>`
describes the contract (fields, types, relations), `impl.models.<type>` binds it to
a PHP class (`class`) and access methods. Behind a model there may be an ORM entity
(`App\Entity\User`), or a plain command object (`App\Model\Login`,
`App\Model\ResetUserPassword`): for the config there is no difference, the requirements for both
are the same (an `id` attribute with a getter, pairing of schema↔impl keys).

**An action is an operation (`create`/`list`/`update`/...) on a model, not
a separate entity.** `schema.actions.<type>` describes access/writable/
include/config, `impl.actions.<type>` — which code performs the operation
(`entityLocator`/`entitySaver`/...). The same type may have several
actions, and they all work with the same model.

**The whole difference between a "regular" and a "custom" action is in one field,
`entitySaver`.** If there is an ORM entity behind the model and standard
saving is enough, specify `entitySaver: doctrine`. If domain logic is needed,
write your own class (`implements EntitySaverInterface`), register it in
`impl.savers` under an alias and put this alias into `entitySaver`. The saver class
itself receives the DTO model object as input, executes the logic, calls `flush` if
necessary, and writes the result back to the DTO fields (for example `token`).

Typical signs of a model with a custom saver:

- the input is a **DTO command**, not an ORM entity (`App\Model\Login`,
  `App\Model\ChangePassword`, `App\Model\ResetUserPassword`);
- the real entity comes as a **relation** via `writable: { user: reference }`
  and the declaration `?included=user`, not as the model itself; a relation that the saver
  only fills for the response is described **only** in `include` — it does not belong in
  `writable` (see [actions.md](actions.md), "writable, include, includable and the
  `included` parameter");
- inside the saver there is domain logic (password check, hashing, writing to
  history, token generation) and an explicit `flush`;
- the result of the operation is returned via DTO fields and `include`.

Example: the `resetUserPassword` model is a DTO with a single relation `user`. The `create`
action accepts the user by `reference` and delegates to a custom saver,
which sets the password and the change flag:

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

The same in JSON:

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

> The binding between an alias and a class is strict in both directions: each used
> `entitySaver` must be declared in `impl.savers`, and each declared
> saver must be used by at least one action (otherwise — the errors
> `references undefined ... saver` / `Saver '...' is declared but not used`,
> see [config.md](config.md), "Config validation").
