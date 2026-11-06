# Ошибки: что бросать, что ловить, где граница

Фреймворк делит исключения на три категории. Категория — это маркер-интерфейс,
который реализует класс исключения:

| Категория     | Маркер                                                  | Смысл                                                                                  | Ответ                            |
| ------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------- | -------------------------------- |
| доменная      | `UniMethod\Frame\Exception\FrameDomainException`        | детерминирована вводом клиента, безопасно показать                                     | 4xx, код из `getHttpErrorCode()` |
| конфигурация  | `UniMethod\Frame\Exception\FrameConfigurationException` | конфиг, `impl`, прикладные реализации и сборка `ApiService` не согласованы между собой | 500                              |
| без категории | —                                                       | сбой (БД, внешний сервис), баг, любые не-фреймовые исключения                          | 500                              |

`ApiService::execute` ловит только доменные и превращает их в ответ; всё
остальное **пробрасывает наверх**, на границу приложения. Ошибок конфигурации
`execute` не оформляет в ответ ни в каком виде — ни исключением, ни `ApiResult`
с кодом 500.

Доменные исключения вам, как правило, бросать не нужно — фреймворк бросает их сам
в ходе маппинга и проверок. Соответствие кодам:

- `404` — `EntityNotFoundException` (запрошенный id не найден);
- `422` — структурные ошибки тела: `TypeMismatchException` (только на входе),
  `RelationTypeMismatchException`, `UpdateRequiresIdException`,
  `TypeCastException`, `InvalidCastInputException` и наследники
  `ValidatorException` (`UnknownPropertyException`, `UnknownRelationException`,
  `UnwritableRelationException`, `RelationModeNotAllowedException`,
  `RelationNotIncludableException`, `InvalidDataStructureException`);
- `400` — кривой ввод: невалидный JSON тела (`JsonDecodeException`,
  `InvalidJsonStructureException`), невалидные параметры list-запроса
  (`InvalidListQueryException`) — в том числе поле фильтра, не заявленное в
  `config.filters`, оператор вне `operators` правила и неизвестный ключ
  оператора (раньше всё это молча пропускалось);
- `401` / `403` — запрет доступа к оператору фильтра
  (`ForbiddenOperatorException`): оператор заявлен в правиле в форме
  `{ operator, access }`, но текущему пользователю не хватает прав. 401 — если
  `access.authRequired`, а `getCurrentUser()` вернул `null`, или
  `UserProviderInterface` не передан; иначе 403. Не путать с 400 выше — там
  оператор для пути не заявлен вовсе.

Ошибки конфигурации (500, **не ловятся** в `execute`):

| Исключение                                                                                      | Что не согласовано                                                                                                                                                 |
| ----------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `InvalidJsonException`, `InvalidConfigStructureException`, `MissingRequiredFieldException`      | файл конфига не читается или не проходит валидацию при загрузке                                                                                                    |
| `ActionAliasNotConfiguredException`                                                             | у действия не задан `entityLocator`/`entitySaver`/`entityRemover` (ловится валидацией конфига; в рантайме значит, что конфиг собран в обход загрузчика)            |
| `LocatorNotConfiguredException`, `SaverNotConfiguredException`, `RemoverNotConfiguredException` | алиас есть в конфиге, но не передан в `ListLocators`/`ListSavers`/`ListRemovers`                                                                                   |
| `ActionNotConfiguredException`                                                                  | действия нет в `Config`, переданном компоненту (компоненты собраны с разными конфигами)                                                                           |
| `ConfigAlreadySetException` / `ConfigNotSetException`                                           | конфиг сервиса задан повторно / сервис вызван без конфига                                                                                                        |
| `ModelNotFoundException`                                                                        | модель не найдена: действие есть, модели нет, или связь ссылается на неизвестную модель                                                                            |
| `MissingEntityLocatorException`                                                                 | связи или update нужен локатор, а у модели его нет                                                                                                                 |
| `MissingIdAttributeException`                                                                   | цель ссылки (reference) — модель без атрибута `id`; только при ручной сборке `Config`, загрузчик это отклоняет (`validateModelIds`)                                |
| `DtoReferencedException`                                                                        | reference-связь ведёт на модель без локатора (DTO)                                                                                                                 |
| `InvalidWritableConfigModeException`                                                            | значение в `writable` не `RelationWriteMode`                                                                                                                       |
| `EntityInstantiationException`                                                                  | класс модели не инстанцируется с параметрами из конфига                                                                                                            |
| `AttributeNotFoundException`, `GetterNotCallableException`, `SetterNotCallableException`        | `impl.models` не совпадает с классом                                                                                                                               |
| `ResponseTypeMismatchException`                                                                 | при маппинге ответа объект не того класса или геттер вернул значение не того вида (не объект для `*ToOne`, не iterable для `*ToMany`, не массив для `type: "…[]"`) |
| `AmbiguousRepositoryException`                                                                  | под `config.repository` list-действия подошло (instanceof) больше одного экземпляра из `repositories`; ловится в конструкторе `ApiService`                        |
| `ListActionMisconfiguredException`                                                              | у list-действия нет `config.repository`, репозиторий не передан в `ApiService` (`repositories`), путь фильтра не резолвится в схему                                |
| `UnknownFilterFieldException`                                                                   | путь фильтра из конфига не резолвится (у целевой модели нет атрибута `id` и т.п.)                                                                                  |
| `UnknownTypeException`                                                                          | тип атрибута не распознан                                                                                                                                          |
| `RuleCatalogNotReadableException`                                                               | не читается каталог правил ограничений                                                                                                                             |

Без категории (500): `JsonEncodeException`, а также любые не-фреймовые
(`PDOException`, `TypeError` и пр.).

## Что бросать из своего кода

В прикладном коде (locator/saver/constraint checker/мутатор) бросайте:

- доменное исключение фреймворка, если ситуация клиентская и уже покрыта типом —
  например `EntityNotFoundException`, если сами обнаружили отсутствие записи;
- свои **доменные** исключения — реализуйте `FrameDomainException` и верните
  нужный код, тогда `execute` оформит их в ответ автоматически;
- свои исключения **конфигурации** — реализуйте `FrameConfigurationException`
  (методов у маркера нет), если ситуация означает рассогласование вашего кода с
  конфигом: сейвер получил не ту модель, правило неприменимо к объекту и т.п.
  Граница отличит их от сбоев;
- обычное исключение/ошибку для сбоев (БД, внешний сервис) — не помечайте маркером,
  оно улетит в 500 и будет залогировано на границе.

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

Не глушите ошибку конфигурации: `return`/`continue` на «не нашёл сервис», «путь
недостижим», «неизвестный алиас» превращает её в молча отключённое правило — без
ответа клиенту и без строки в логе.

## Граница приложения

`ApiService::execute` никогда не «теряет» сбой в ответе клиента — несведённые
исключения он пробрасывает. Поэтому **вы** обязаны обернуть вызов на своей точке
входа (front controller, swoole-воркер, CLI), залогировать с трейсом и оформить 500.
Ошибки конфигурации стоит ловить отдельной веткой — так их видно в логе и
мониторинге отдельно от сбоев:

```php
try {
    $result = $apiService->execute($modelType, $actionType, $query, $rawBody, $id);
} catch (FrameConfigurationException $e) {
    $logger->critical('API configuration error', ['exception' => $e]);
    $result = new ApiResult(500, 'Internal Server Error', [], false);
} catch (\Throwable $e) {
    $logger->error($e->getMessage(), ['exception' => $e]);
    // здесь же — откат транзакции/миграции для swoole-воркера, если нужно
    $result = new ApiResult(500, 'Internal Server Error', [], false);
}
```

В dev-окружении граница может отдавать в ответе класс и текст исключения; в
prod — только обобщённое сообщение.

## Логирование доменных (опционально)

Доменные исключения по умолчанию **не** логируются — это нормальный поток, а не
сбой. Если нужна аналитика/аудит по ним, передайте в `ApiService` реализацию
`DomainExceptionObserverInterface`:

```php
interface DomainExceptionObserverInterface
{
    public function onDomainException(FrameDomainException $exception): void;
}
```

Не передан — поведение прежнее (доменные просто превращаются в ответ). Передан —
вызывается в catch-ветке доменных до формирования ответа. Сбои (500) сюда не
попадают: их логируете на границе («Граница приложения»).
