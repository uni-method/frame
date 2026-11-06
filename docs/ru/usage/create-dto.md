# Добавление DTO на создание

## Когда это обосновано

Выбор между отдельным DTO на создание и прямым маппингом на сущность разобран в [persistence-strategy.md](persistence-strategy.md) (стратегия C против A/B). Коротко: DTO нужен, когда контракт запроса/ответа не совпадает со структурой таблицы — вычисляемые поля в ответе, расхождение набора полей, доменные правила с управляемыми HTTP-кодами, побочные эффекты. Если создание сводится к «принять поля и сохранить» — DTO не нужен, используйте сущность напрямую.

## Шаги

### 1. Модель запроса

`src/Api/Model/CreateSomething.php` — обычный POPO с публичными nullable-свойствами и `getId()`. Валидационные атрибуты Symfony вешаются здесь же; пользовательские constraint-ы (например проверка прав на основе текущего пользователя) — тоже. Проверка выполняется в фазе `AfterInput` с группами `['Default', 'create']` — у DTO создания фазы `AfterLocate` нет, объект из БД не существует. Правила по типу (обязательность, формат) для DTO можно описать и в секции `constraints` конфига — модель `createSomething` там такая же модель, как сущность.

```php
final class CreateSomething
{
    #[Assert\NotNull]
    #[Assert\Email]
    public string|null $email = null;

    #[Assert\NotNull]
    #[CustomAccessConstraint]
    public string|null $role = null;

    public string|null $password = null;   // заполняется сервером, отдаётся клиенту

    public Entity|null $result = null;      // связь, если нужен include

    public function getId(): string
    {
        return md5((string) $this->email);
    }
}
```

Правила по типам полей:

- Поле, которое клиент **присылает**, но не должен видеть в ответе (текущий пароль и т.п.) — `mutators.beforeOutput: [hide]` в схеме.
- Поле, которое сервер **заполняет, чтобы показать** (сгенерированный пароль) — без `hide`, оно для того и существует.

### 2. Сейвер

`src/Api/Savers/CreateSomething.php` реализует `EntitySaverInterface`. Структура `save()`:

1. Проверка типа входа → `UnexpectedSaverInputException` (это ошибка конфигурации, не клиентская: реализует `FrameConfigurationException`, улетает в 500).
2. Проверки обязательных полей и доменных правил → `ValidationException` (422) или `AccessDeniedException` (403).
3. Проверки целостности, которые иначе упадут на уровне БД (занятый уникальный ключ) → `ValidationException` **до** `flush()`.
4. Построение и сохранение сущности.
5. Запись вычисленных значений обратно в модель (`$model->password = ...`, `$model->result = ...`) и `return $model`.

```php
public function save(object $objectToSave): CreateSomethingModel
{
    if (!$objectToSave instanceof CreateSomethingModel) {
        throw new UnexpectedSaverInputException("Only CreateSomething can be handled");
    }

    if (!$objectToSave->email) {
        throw new ValidationException("Email is required");
    }

    if ($this->em->getRepository(Entity::class)->findOneBy(["email" => $objectToSave->email])) {
        throw new ValidationException("Entity with this email already exists");
    }

    // ... построение сущности, побочные эффекты ...
    $this->em->persist($entity);
    $this->em->flush();

    $objectToSave->password = $generatedPassword;
    $objectToSave->result = $entity;

    return $objectToSave;
}
```

Порядок исключений важен: тип входа → обязательность → права → целостность → запись. Проверку занятости уникального поля делайте всегда до `flush()`, иначе вместо 422 получите 500.

### 3. Исключения

Используйте существующие из `src/Api/Exception/`, не плодите новые без нужды:

- `ValidationException` → 422, общая доменная ошибка с произвольным текстом.
- `AccessDeniedException` → 403, нужен авторизованный/правомочный пользователь.
- `InvalidCredentialsException` → 401.
- `UnexpectedSaverInputException` → **не** доменное: реализует `FrameConfigurationException`, а не `FrameDomainException`, улетает в 500 и логируется на границе как ошибка конфигурации. Только для рассогласования сейвер↔модель.

### 4. Описание в конфиге API

В конфиге ([domains/config.md](../domains/config.md)) дополняются четыре места.

`schema.models` — контракт модели:

```yaml
createSomething:
  attributes:
    id: { type: "string|null", writable: false }
    email: { type: "string|null" }
    role: { type: "string|null" }
    password: { type: "string|null" }
  relations:
    result:
      type: oneToOne
      targetModel: something
```

`schema.actions` — экшен, доступ, include. Роли в `access.roles` — это грубый фильтр «кто вообще может дёргать ручку»; тонкая проверка (кто кого создаёт) делается валидатором модели:

```yaml
createSomething:
  create:
    comment: "Краткое описание поведения и правил доступа."
    access:
      authRequired: true
      roles: ["ROLE_OWNER", "ROLE_ADMIN"]
    include:
      - result
```

Семантика `include` и `writable` на пишущих экшенах (полное описание —
[domains/actions.md](../domains/actions.md), «writable, include, includable и параметр `included`»):

- `writable` — вход: что клиент может задекларировать в `?included=` и
  передать в теле. Декларация обязательна: связь в теле без `?included=` → 422,
  задекларированная связь без тела → 422. Пишется декларация ∩ `writable`.
- `include` — выход: перечисленные связи всегда в ответе, query не нужен. Так
  клиент читает результат работы сейвера (например, `marker.statusHistory`).
- Связь, которую сервер заполняет сам, описывается **только** в `include`.
  В `writable` её не добавляют: `writable` — это вход, и такая запись откроет
  клиенту запись в выходную связь.

`impl.savers` — регистрация сейвера:

```yaml
createSomethingSaver: 'App\Api\Savers\CreateSomething'
```

`impl.models` — маппинг модели на класс с геттерами/сеттерами:

```yaml
createSomething:
  class: 'App\Api\Model\CreateSomething'
  attributes:
    id: { getter: getId }
    email: { getter: email, setter: email }
    role: { getter: role, setter: role }
    password: { getter: password, setter: password }
  relations:
    result: { getter: result, setter: result }
```

`impl.actions` — привязка экшена к сейверу:

```yaml
createSomething:
  create:
    entitySaver: createSomethingSaver
```

### 5. Тесты

Feature-файл в стиле существующих (`auth.feature`, `reset_password.feature`): сценарии на успех, на каждое доменное правило (занятый email → 422, недостаточно прав → 422/403), на неаутентифицированный запрос (403). Поля, которые сервер вычисляет, проверяются через `the item field ... is not null`; побочные эффекты в БД — отдельным шагом контекста, который читает запись напрямую через `EntityManager`.

## Чеклист

- [ ] Создание реально нетривиально (вычисляемые поля / расхождение контракта с сущностью / доменные правила / побочные эффекты) — иначе DTO не нужен.
- [ ] Модель: nullable-свойства, `getId()`, валидационные атрибуты.
- [ ] Поля ответа помечены `hide` только если их нельзя показывать.
- [ ] Сейвер: проверка типа входа первой; занятость уникального ключа — до `flush()`.
- [ ] Исключения переиспользованы из `Api/Exception`, коды корректны (422/403/401/500).
- [ ] Конфиг дополнен в четырёх местах: `schema.models`, `schema.actions`, `impl.savers`/`impl.models`, `impl.actions`.
- [ ] Feature-тесты: успех, каждое правило, неаутентифицированный запрос, проверка побочных эффектов в БД.
