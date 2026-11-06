# Доступ

## `access` в конфиге

`access` — объект `{ authRequired?: bool, roles?: string[] }`. Если он
«тривиальный» (`authRequired:false` и нет `roles`), он схлопывается в `null` —
то есть `access: { authRequired: false }` равнозначно отсутствию `access`.

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

`userModel` указывает тип модели, представляющей пользователя. Класс этой модели
обязан реализовывать `UniMethod\Frame\Integration\UserInterface`.

```json
{ "impl": { "userModel": "user" } }
```

```yaml
impl:
  userModel: user
```

## Провайдер пользователя и доступ

Нужен, только если в конфиге действий есть `access`/`includable`/доступ к
операторам фильтра. Если контроль доступа не используется — не передавайте
провайдер (`ApiService` принимает его как необязательный, по умолчанию `null`).

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

`UserInterface` — ваш пользователь, минимально: идентификатор, идентификатор для
аутентификации, роли и проверка роли.

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

Фреймворк вызывает `canAccessAction` для действия в целом и `canAccess` для
точечных правил (`access` на writable-пути, на пути `includable`, на операторе
фильтра). Вам достаточно вернуть `bool`; реакция на отказ зависит от правила:
действие, writable-путь и оператор фильтра — 401, если `access.authRequired`, а
`getCurrentUser()` вернул `null`, иначе 403; путь `includable` — молча
исключается из выдачи. Без `UserProviderInterface` любой нетривиальный `access`
считается запрещённым (fail-closed) — 401.
