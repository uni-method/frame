# Errors: what to throw, what to catch, where the boundary is

The framework divides exceptions into three categories. A category is a marker interface
that the exception class implements:

| Category      | Marker                                                  | Meaning                                                                                | Response                         |
| ------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------- | -------------------------------- |
| domain        | `UniMethod\Frame\Exception\FrameDomainException`        | determined by client input, safe to show                                               | 4xx, code from `getHttpErrorCode()` |
| configuration | `UniMethod\Frame\Exception\FrameConfigurationException` | the config, `impl`, application implementations and the `ApiService` assembly are inconsistent with each other | 500                              |
| no category   | —                                                       | a failure (DB, external service), a bug, any non-frame exceptions                      | 500                              |

`ApiService::execute` catches only domain exceptions and turns them into a response; everything
else is **rethrown upwards**, to the application boundary. `execute` does not turn
configuration errors into a response in any form — neither as an exception nor as an `ApiResult`
with code 500.

As a rule, you do not need to throw domain exceptions — the framework throws them itself
during mapping and checks. Mapping to codes:

- `404` — `EntityNotFoundException` (the requested id is not found);
- `422` — structural errors of the body: `TypeMismatchException` (input only),
  `RelationTypeMismatchException`, `UpdateRequiresIdException`,
  `TypeCastException`, `InvalidCastInputException` and subclasses of
  `ValidatorException` (`UnknownPropertyException`, `UnknownRelationException`,
  `UnwritableRelationException`, `RelationModeNotAllowedException`,
  `RelationNotIncludableException`, `InvalidDataStructureException`);
- `400` — malformed input: invalid body JSON (`JsonDecodeException`,
  `InvalidJsonStructureException`), invalid list request parameters
  (`InvalidListQueryException`) — including a filter field not declared in
  `config.filters`, an operator outside the rule's `operators` and an unknown operator
  key (previously all of this was silently skipped);
- `401` / `403` — denied access to a filter operator
  (`ForbiddenOperatorException`): the operator is declared in the rule in the form
  `{ operator, access }`, but the current user lacks the rights. 401 — if
  `access.authRequired` and `getCurrentUser()` returned `null`, or
  `UserProviderInterface` was not passed; otherwise 403. Not to be confused with the 400 above — there
  the operator is not declared for the path at all.

Configuration errors (500, **not caught** in `execute`):

| Exception                                                                                       | What is inconsistent                                                                                                                                               |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `InvalidJsonException`, `InvalidConfigStructureException`, `MissingRequiredFieldException`      | the config file cannot be read or does not pass validation at load                                                                                                 |
| `ActionAliasNotConfiguredException`                                                             | the action has no `entityLocator`/`entitySaver`/`entityRemover` set (caught by config validation; at runtime it means the config was assembled bypassing the loader) |
| `LocatorNotConfiguredException`, `SaverNotConfiguredException`, `RemoverNotConfiguredException` | the alias is in the config but not passed to `ListLocators`/`ListSavers`/`ListRemovers`                                                                            |
| `ActionNotConfiguredException`                                                                  | the action is not in the `Config` passed to the component (components assembled with different configs)                                                          |
| `ConfigAlreadySetException` / `ConfigNotSetException`                                           | the service config is set again / the service is called without a config                                                                                          |
| `ModelNotFoundException`                                                                        | the model is not found: the action exists, the model does not, or a relation refers to an unknown model                                                            |
| `MissingEntityLocatorException`                                                                 | a relation or update needs a locator, but the model has none                                                                                                       |
| `MissingIdAttributeException`                                                                   | the reference target is a model without an `id` attribute; only with manual assembly of `Config`, the loader rejects this (`validateModelIds`)                      |
| `DtoReferencedException`                                                                        | a reference relation leads to a model without a locator (DTO)                                                                                                      |
| `InvalidWritableConfigModeException`                                                            | a value in `writable` is not a `RelationWriteMode`                                                                                                                 |
| `EntityInstantiationException`                                                                  | the model class cannot be instantiated with the parameters from the config                                                                                         |
| `AttributeNotFoundException`, `GetterNotCallableException`, `SetterNotCallableException`        | `impl.models` does not match the class                                                                                                                             |
| `ResponseTypeMismatchException`                                                                 | when mapping the response, an object of the wrong class or the getter returned a value of the wrong kind (not an object for `*ToOne`, not an iterable for `*ToMany`, not an array for `type: "…[]"`) |
| `AmbiguousRepositoryException`                                                                  | more than one instance from `repositories` matched (instanceof) the list action's `config.repository`; caught in the `ApiService` constructor                     |
| `ListActionMisconfiguredException`                                                              | the list action has no `config.repository`, the repository is not passed to `ApiService` (`repositories`), the filter path does not resolve in the schema         |
| `UnknownFilterFieldException`                                                                   | a filter path from the config does not resolve (the target model has no `id` attribute, etc.)                                                                      |
| `UnknownTypeException`                                                                          | the attribute type is not recognized                                                                                                                               |
| `RuleCatalogNotReadableException`                                                               | the constraint rule catalog cannot be read                                                                                                                         |

No category (500): `JsonEncodeException`, as well as any non-frame ones
(`PDOException`, `TypeError`, etc.).

## What to throw from your code

In application code (locator/saver/constraint checker/mutator) throw:

- a framework domain exception if the situation is a client one and is already covered by a type —
  for example `EntityNotFoundException` if you yourself detected that a record is missing;
- your own **domain** exceptions — implement `FrameDomainException` and return
  the required code, then `execute` will turn them into a response automatically;
- your own **configuration** exceptions — implement `FrameConfigurationException`
  (the marker has no methods) if the situation means a mismatch between your code and
  the config: the saver received the wrong model, a rule is not applicable to the object, etc.
  The boundary will distinguish them from failures;
- a regular exception/error for failures (DB, external service) — do not mark it with a marker,
  it will fly to a 500 and will be logged at the boundary.

```php
final class PaymentRequiredException extends \RuntimeException
    implements FrameDomainException
{
    public function getHttpErrorCode(): int
    {
        return 402;
    }
}

final class UnexpectedSaverInputException extends \RuntimeException
    implements FrameConfigurationException
{
}
```

Do not suppress a configuration error: `return`/`continue` on "service not found", "path
unreachable", "unknown alias" turns it into a silently disabled rule — with no
response to the client and no line in the log.

## The application boundary

`ApiService::execute` never "loses" a failure in the client response — it rethrows unhandled
exceptions. Therefore **you** must wrap the call at your entry
point (front controller, swoole worker, CLI), log it with the trace and produce a 500.
Configuration errors are worth catching in a separate branch — this way they are visible in the log and
monitoring separately from failures:

```php
try {
    $result = $apiService->execute($modelType, $actionType, $query, $rawBody, $id);
} catch (FrameConfigurationException $e) {
    $logger->critical('API configuration error', ['exception' => $e]);
    $result = new ApiResult(500, 'Internal Server Error', [], false);
} catch (\Throwable $e) {
    $logger->error($e->getMessage(), ['exception' => $e]);
    // this is also the place to roll back a transaction/migration for a swoole worker, if needed
    $result = new ApiResult(500, 'Internal Server Error', [], false);
}
```

In a dev environment the boundary may return the exception class and message in the response; in
prod — only a generic message.

## Logging domain exceptions (optional)

Domain exceptions are **not** logged by default — this is a normal flow, not
a failure. If you need analytics/audit on them, pass an implementation of
`DomainExceptionObserverInterface` to `ApiService`:

```php
interface DomainExceptionObserverInterface
{
    public function onDomainException(FrameDomainException $exception): void;
}
```

Not passed — the behavior is as before (domain exceptions are simply turned into a response). Passed —
it is called in the catch branch for domain exceptions before the response is built. Failures (500) do not
get here: you log them at the boundary ("The application boundary").
