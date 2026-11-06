# Access

## `access` in the config

`access` is an object `{ authRequired?: bool, roles?: string[] }`. If it is
"trivial" (`authRequired:false` and no `roles`), it collapses into `null` —
that is, `access: { authRequired: false }` is equivalent to the absence of `access`.

```json
{
  "schema": {
    "models": { "item": { "attributes": { "id": { "type": "int" } } } },
    "actions": {
      "item": {
        "create": {
          "access": { "authRequired": true, "roles": ["admin", "editor"] }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    item:
      attributes:
        id:
          type: int
  actions:
    item:
      create:
        access:
          authRequired: true
          roles: [admin, editor]
```

## userModel

`userModel` specifies the type of the model that represents the user. The class of this model
must implement `UniMethod\Frame\Integration\UserInterface`.

```json
{ "impl": { "userModel": "user" } }
```

```yaml
impl:
  userModel: user
```

## User provider and access

It is needed only if the action config has `access`/`includable`/access to
filter operators. If access control is not used, do not pass
the provider (`ApiService` accepts it as optional, `null` by default).

```php
interface UserProviderInterface
{
    public function getCurrentUser(): ?UserInterface;
    public function canAccessAction(Action $action): bool;
    public function canAccess(AccessConfig $config): bool;

    /** @param string[] $requiredRoles */
    public function userHasAnyRole(UserInterface $user, array $requiredRoles): bool;
}
```

`UserInterface` is your user, minimally: an identifier, an identifier for
authentication, roles and a role check.

```php
interface UserInterface
{
    public function getId(): int;
    public function getUserIdentifier(): string|int;
    /** @return string[] */
    public function getRoles(): array;
    public function hasRole(string $role): bool;
}
```

The framework calls `canAccessAction` for the action as a whole and `canAccess` for
targeted rules (`access` on a writable path, on an `includable` path, on a filter
operator). It is enough for you to return a `bool`; the reaction to a denial depends on the rule:
an action, a writable path and a filter operator — 401 if `access.authRequired` and
`getCurrentUser()` returned `null`, otherwise 403; an `includable` path is silently
excluded from the output. Without `UserProviderInterface` any non-trivial `access`
is considered forbidden (fail-closed) — 401.
