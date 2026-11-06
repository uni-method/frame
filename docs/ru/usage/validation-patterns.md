# Паттерны ограничений: что закладывать сразу

Каталог для написания новой сущности, DTO или экшена. Правило одно: проверка живёт в констрейнте на объекте, сейвер — только для побочных эффектов, локатор — только для поиска по id. Выбор стратегии сохранения — в [persistence-strategy.md](persistence-strategy.md), граница между правилами в `constraints` конфига и правилами в коде — в [domains/constraints.md](../domains/constraints.md) («Граница спецификации и локального кода»), здесь — готовые формы для каждого типа правила в коде.

Два слоя правил на одном классе:

- **сгенерированные прибилдом** из секции `constraints` (обязательность, длины, форматы, словари, диапазоны) — приходят отдельным источником метаданных Symfony Validator и **не повторяются** атрибутами;
- **локальные** — атрибуты `Assert\*` и свои констрейнты для всего, что ссылается на `this`, другое свойство, пользователя, БД, предыдущее состояние.

Паттерны 1 (обязательность, словари) показаны атрибутами для проектов без прибилда; при наличии секции `constraints` они пишутся там, а в коде остаются паттерны 2–13.

## Что никогда не пишется в сейвере и локаторе

- обязательность, словари, форматы;
- согласованность связей (сорт той же культуры, зона того же огорода);
- условная обязательность и запреты по состоянию;
- принадлежность текущему пользователю и права по роли — в том числе на **чтение и удаление**;
- неизменяемость поля после создания;
- агрегатные условия по связям объекта.

Всё это — атрибуты на сущности/DTO с сообщением, которое ждёт клиент. Сейвер остаётся только когда есть побочный эффект, upsert, выборка из БД или объект, который сейвер выбирает сам. Кастомный локатор ради «автору и админу можно, остальным нельзя» не пишется: это паттерн 7 с группами действий.

## Фазы и группы

Фреймворк вызывает адаптер (`ConstraintCheckerInterface`) в трёх фазах и передаёт контекст: действие, фазу, текущего пользователя, id, снимок объекта до изменений ([domains/constraints.md](../domains/constraints.md), «Проверка ограничений в рантайме»). Мост в Symfony проецирует контекст в группы валидации — это единственный контракт, на который опираются атрибуты:

| Фаза          | Действия             | Группы                  | Объект                               |
| ------------- | -------------------- | ----------------------- | ------------------------------------ |
| `AfterLocate` | read, update, delete | `[<action>]`            | сущность из БД, вход ещё не применён |
| `AfterInput`  | create, update       | `['Default', <action>]` | сущность/DTO после применения входа  |
| `ListQuery`   | list                 | `['Default']`           | `{Model}ListRequest`                 |

Следствия:

- Констрейнт **без** `groups` живёт в `Default` и выполняется только в `AfterInput`/`ListQuery` — на хранимом объекте при чтении он не запускается, поэтому записи с устаревшими данными не падают 422 на `read`.
- Правило «кому можно этот объект» помечается группами действий: `groups: ['read', 'update', 'delete']`. В `update` оно выполнится **до** применения входа, на состоянии из БД.
- Правило, нужное только при создании/изменении, — `groups: ['create']` / `groups: ['update']` (аналог `create:`/`update:` в секции `constraints`).
- Непустой результат фазы останавливает пайплайн: при провале `AfterLocate` вход не маппится и сейвер не вызывается.
- `list` фазой `AfterLocate` не покрывается: владение в списке — обязательный фильтр по пользователю или репозиторий, не пост-фильтрация.
- Отказ в `AfterLocate` на `read` отдаёт 403 и тем самым подтверждает существование записи. Если это утечка — пока только кастомный локатор, возвращающий `null`; опция «403 как 404» планируется.

## 1. Обязательность и словари

```php
#[ORM\Column(length: 32)]
#[Assert\NotBlank(message: 'Выберите тип зоны')]
#[Assert\Choice(choices: self::TYPES, message: 'Выберите тип зоны')]
private ?string $zoneType = null;

#[ORM\ManyToOne(targetEntity: Garden::class)]
#[ORM\JoinColumn(nullable: false)]
#[Assert\NotNull(message: 'Укажите огород')]
private ?Garden $garden = null;
```

- Списки значений — константы `self::TYPES` на сущности, `Choice` ссылается на них.
- Строка из DTO, где пробелы = пусто: `NotBlank(normalizer: 'trim')`.
- Сообщение — часть контракта, задаётся сразу; дефолтные английские тексты допустимы только для технических полей, которые клиент не заполняет.

## 2. Согласованность двух связей

Связь должна относиться к тому же родителю, что и объект:

```php
#[Assert\Expression(
    'value === null or this.getGarden() === null or value.getGardenId() === this.getGarden().getId()',
    message: 'Зона относится к другому огороду',
)]
private ?GardenZone $gardenZone = null;
```

- Каждый шаг цепочки гардится от `null` — вызов метода на `null` в ExpressionLanguage бросает исключение.
- Сравниваются идентификаторы (`getXId()`), не объекты.
- Для связи через один шаг вверх (правило → посадка пометки): `'value === null or this.getPlanting() === null or not value.isCustom() or value.getPlantingId() === this.getPlanting().getId()'`.

## 3. Условно обязательное поле

```php
#[Assert\When(
    expression: 'this.getPlantingMethod() === seeds',
    values: ['seeds' => self::METHOD_SEEDS],
    constraints: [new Assert\NotNull(message: 'Для выбранного способа выращивания обязательно поле «Дата посева»')],
)]
private ?DateTimeImmutable $sownAt = null;
```

- Константы передаются через `values`, литералы в строку выражения не пишутся.
- Набор значений — `'this.getPlantingMethod() in planted'` с `values: ['planted' => [...]]` (`in` сравнивает значения, не ключи).
- Одно правило — одно `When`; несколько полей по одному условию — `When` на каждом поле.

## 4. Либо A, либо B

Два класс-уровневых `Expression`, по одному на каждый текст ошибки:

```php
#[Assert\Expression(
    'this.getCareProgramVersion() === null or this.getPlanting() === null',
    message: 'Правило принадлежит либо версии программы, либо посадке',
)]
#[Assert\Expression(
    'this.getCareProgramVersion() !== null or this.getPlanting() !== null',
    message: 'Укажите версию программы или посадку',
)]
class CareRule
```

Один XOR с общим сообщением не пишется: клиент должен видеть, какое из двух правил нарушено.

## 5. Запрет по состоянию

Действие недопустимо при определённом статусе объекта или связи:

```php
#[Assert\When(
    expression: 'this.getStatus() === done',
    values: ['done' => self::STATUS_DONE],
    constraints: [new Assert\IsNull(message: 'Выполненную задачу нельзя перенести')],
)]
private ?DateTimeImmutable $movedToDate = null;

#[Assert\Expression(
    expression: 'value === null or value.getSetupStatus() !== completed',
    values: ['completed' => Garden::SETUP_COMPLETED],
    message: 'Настройка огорода уже завершена',
)]
public Garden|null $garden = null;
```

Статусные методы сущности (`isSetupAtLeast`, `isCustom`, `isActive`) вызываются прямо из выражения — это их назначение.

## 6. Даты

```php
#[Assert\GreaterThanOrEqual('today', message: 'Нельзя перенести задачу в прошлое')]
private ?DateTimeImmutable $movedToDate = null;
```

Строка входа парсится в сеттере в `DateTimeImmutable`; констрейнты стоят на типизированном свойстве, не на строке. Некорректная дата даёт `null` → ловится `NotNull`.

## 7. Владелец — текущий пользователь

На каждой сущности, принадлежащей пользователю, класс-уровневый `OwnedByCurrentUser` с путём до `User` и группами всех действий, где владение проверяется:

```php
#[OwnedByCurrentUser(
    path: 'user',
    message: 'Огород принадлежит другому пользователю',
    groups: ['read', 'update', 'delete'],
)]
class Garden

#[OwnedByCurrentUser(
    path: 'garden.user',
    message: 'Посадка принадлежит другому пользователю',
    groups: ['read', 'update', 'delete'],
)]
class Planting
```

- Путь всегда до пользователя, не до его id.
- `null` на пути пропускается: обязательность — отдельным `NotNull`, а владельца корневой сущности проставляет сейвер/мутатор.
- Нарушение — `AccessDeniedException` (403), не violation (422); см. «Коды ответов».
- Текст сообщения — единый на проект для каждого типа объекта («Огород принадлежит…», «Посадка принадлежит…»).
- Группы — действия, на которых объект уже существует (`read`, `update`, `delete`): проверка идёт в фазе `AfterLocate`, на объекте из БД. `create` сюда не входит — владельца нового объекта проставляет сейвер/мутатор, а чужую связь при создании ловит паттерн 2.
- Исключение для роли («автору и админу») — параметр констрейнта, не второе правило:

```php
#[OwnedByCurrentUser(
    path: 'author',
    exceptRoles: [User::ROLE_ADMIN],
    message: 'Пост принадлежит другому пользователю',
    groups: ['read', 'update', 'delete'],
)]
class Post
```

Это и есть замена кастомному локатору: `entityLocator: doctrine`, атрибут на классе, feature-тест на 403 для чужого пользователя и 200 для автора и админа.

## 8. Поле только для роли

```php
#[HasRole(User::ROLE_ADMIN, message: 'Правило программы ухода может редактировать только администратор')]
private ?CareProgramVersion $careProgramVersion = null;
```

Заполненное значение требует роли, пустое пропускается. Грубый фильтр «кто вообще может дёргать ручку» — `access.roles` в конфиге; `HasRole` — для полей, которые в одном экшене доступны разным ролям.

## 9. Неизменяемое после создания

```php
#[Assert\NotNull(message: 'Укажите огород')]
#[UnchangedOnUpdate(message: 'Зону нельзя перенести в другой огород', groups: ['update'])]
private ?Garden $garden = null;
```

Группа `update` — правило имеет смысл только в `AfterInput` при изменении; значение «до» валидатор берёт из `ConstraintContext::$original` (снимок до применения входа), а не из UnitOfWork.

Связь при этом **остаётся** в `writable` у `update` — иначе клиент получит общее «Relation ... is not writable» фреймворка вместо доменного текста. Убирать поле из `writable` — только когда его вообще нельзя присылать и текст ошибки не важен.

## 10. Агрегат по связям

Условие с циклом по коллекции — `Callback` с именованным методом, нарушение привязывается к полю-триггеру:

```php
#[Assert\Callback('validateCompletion')]
final class GardenSetup
{
    public function validateCompletion(ExecutionContextInterface $context): void
    {
        if ($this->complete !== true || !$this->garden instanceof Garden) {
            return;
        }

        foreach ($this->garden->getPlantingsAsArray() as $planting) {
            $stage = $planting->getCurrentStage();
            if (!$stage instanceof PlantingStage || $stage->getConfirmedAt() === null) {
                $context
                    ->buildViolation('Не подтверждена стадия посадки «{{ planting }}»')
                    ->setParameter('{{ planting }}', $planting->getCustomName() ?? (string) $planting->getCrop()?->getName())
                    ->atPath('complete')
                    ->addViolation();
            }
        }
    }
}
```

Условия без цикла (`getNotificationPreferences() !== null`, `getPlantingsAsArray() != []`) — обычными `Expression` внутри `When`.

## 11. DTO-шаг мастера с вложенной сущностью

DTO присылает вложенную сущность без родителя (родителя проставит сейвер). Обычный `Valid` провалит `NotNull` родителя, поэтому закладывается сразу:

```php
// сущность
public const string GROUP_SETUP = 'setup';

#[Assert\NotNull(message: 'Выберите культуру', groups: ['Default', self::GROUP_SETUP])]
private ?Crop $crop = null;
// … все проверки полей — в обеих группах; garden и OwnedByCurrentUser — только Default

// DTO
#[Assert\GroupSequence(['GardenSetup', Planting::GROUP_SETUP])]
final class GardenSetup
{
    #[Assert\Valid(groups: [Planting::GROUP_SETUP])]
    public Planting|null $planting = null;
}
```

- Первый элемент `GroupSequence` — короткое имя класса DTO: в нём выполняются все проверки самого шага.
- `Valid` с явной группой валидирует вложенный объект в этой группе, а не в `Default`.
- Проверки, которым нужен родитель (зона того же огорода), дублируются на DTO через `this.garden`.
- «Какой шаг» — один класс-уровневый `Expression` со списком заполненных связей и текстом «Не указан шаг…»; проверки шага — `When` по его связи.

## 12. DTO аутентификации и регистрации

```php
#[Assert\NotBlank(message: "Email and password are required")]
public string|null $email = null;

#[Assert\NotBlank(message: "initData is required", normalizer: 'trim')]
public string|null $initData = null;
```

Сейвер таких DTO не повторяет обязательность: он приводит `(string)` и делает своё — проверка credentials (401), активности (403), выдача токена, хеширование, создание пользователя. Уникальность email на DTO остаётся в сейвере (`UniqueEntity` работает на сущностях).

## 13. Свой констрейнт

Пишется, когда правило зависит от окружения: текущий пользователь, роли, предыдущее состояние, репозиторий. Критерий из [domains/constraints.md](../domains/constraints.md): всё, что параметризуется только типом и литералами, — в секцию `constraints`; всё, что ссылается на `this` или окружение, — сюда. Форма:

```php
#[Attribute(Attribute::TARGET_CLASS)]            // или TARGET_PROPERTY
class OwnedByCurrentUser extends Constraint
{
    public string $message = 'Access denied.';
    public string $path;

    public function __construct(string $path, ?string $message = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);
        $this->path = $path;
        if ($message !== null) {
            $this->message = $message;
        }
    }

    public function getTargets(): string { return self::CLASS_CONSTRAINT; }
    public function validatedBy(): string { return OwnedByCurrentUserValidator::class; }
}
```

Валидатор:

- зависимости через конструктор (`Security`, `PropertyAccessorInterface`, `EntityManagerInterface`); текущий пользователь и снимок «до» доступны и из `ConstraintContext`, который мост кладёт в сервис контекста запроса — предпочтительнее `Security`/UnitOfWork, так как одинаково работает во всех фазах;
- первым делом `instanceof` констрейнта → `UnexpectedTypeException`;
- пустое значение — `return`, обязательность не его дело;
- недостижимый путь (сегмента нет, значение по пути не того вида) — ошибка конфигурации констрейнта, не `return`: иначе опечатка в `path` молча отключает правило. Бросайте исключение с маркером `FrameConfigurationException` (в бандле — `ConstraintPathMisconfiguredException`), граница отдаст 500;
- 422 — `buildViolation($constraint->message)->setParameter(...)->addViolation()`;
- 403 — `throw new AccessDeniedException($constraint->message)`.

Существующие: `OwnedByCurrentUser`, `HasRole`, `UnchangedOnUpdate`. Переиспользовать чужой констрейнт (например `MatchIdentity`) можно только если совпадают и статус, и текст ответа.

## Коды ответов и порядок

- Мост (`ConstraintCheckerInterface` → Symfony Validator) отдаёт нарушения как 422 с `source.pointer` = путь свойства. Для 403 валидатор бросает доменный `AccessDeniedException`: валидация прерывается, в ответе ровно одна ошибка.
- Фазы идут последовательно и каждая останавливает следующую: в `update` сначала `AfterLocate` (группа `update`, объект из БД), потом `AfterInput` (`Default` + `update`, объект с применённым входом). Правило из группы `update` без явного различения фаз выполнится дважды — на разном состоянии объекта; это нормально для проверок доступа и неверно для проверок входа, поэтому проверки входа держите в `Default` или различайте фазу через контекст.
- Нарушения копятся, исключение отбрасывает накопленные. Класс-уровневые констрейнты идут раньше свойств, внутри уровня — в порядке объявления. Бросающий констрейнт объявляется после правил, которые должны победить, либо гейтится `When`:

```php
#[Assert\When(
    expression: 'this.getCareProgramVersion() === null',
    constraints: [new OwnedByCurrentUser(path: 'planting.garden.user', message: 'Посадка принадлежит другому пользователю')],
)]
```

Здесь спорное правило (и версия, и посадка) получает 422 из паттерна 4, а не 403 по чужой посадке.

## Что остаётся в сейвере

Сейвер пишется только под это, и в нём нет ни одной проверки из паттернов выше:

- побочные эффекты: пересчёт связанных агрегатов, продвижение статуса, вытеснение старых записей, снимки, каскадное создание;
- upsert по бизнес-ключу;
- проверки, которым нужна выборка из БД или объект, выбранный самим сейвером (посадка по умолчанию);
- аутентификация и выдача токена.

Если после этого списка сейвер пуст — экшен на `entitySaver: doctrine`, сейвер не создаётся.

## Конфиг

- Экшен без логики: `create: { entityLocator: doctrine, entitySaver: doctrine }`.
- Связь, защищённая `UnchangedOnUpdate`, — в `writable` у `update`.
- Поле, которое заполняет сервер (снимок, статус), — `writable: false`; констрейнт на него не ставится (валидация идёт до сейвера).

## Скелет новой пользовательской сущности

```php
#[ORM\Entity]
#[OwnedByCurrentUser(path: 'garden.user', message: 'Посадка принадлежит другому пользователю')]
class Planting
{
    #[ORM\ManyToOne(targetEntity: Garden::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Укажите огород')]
    #[UnchangedOnUpdate(message: 'Посадку нельзя перенести в другой огород')]
    private ?Garden $garden = null;

    #[ORM\ManyToOne(targetEntity: GardenZone::class)]
    #[Assert\Expression(
        'value === null or this.getGarden() === null or value.getGardenId() === this.getGarden().getId()',
        message: 'Зона относится к другому огороду',
    )]
    private ?GardenZone $gardenZone = null;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank(message: 'Выберите способ выращивания')]
    #[Assert\Choice(choices: self::METHODS, message: 'Выберите способ выращивания')]
    private ?string $plantingMethod = null;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_ACTIVE;
}
```

## Чеклист на ревью новой сущности/экшена

- [ ] На каждом обязательном поле и связи — `NotNull`/`NotBlank` с сообщением.
- [ ] Словари — `Choice` по константам сущности.
- [ ] Каждая связь, зависящая от другой, — `Expression` с гардами по `null`.
- [ ] Условные правила — `When` с константами через `values`.
- [ ] Пользовательская сущность — `OwnedByCurrentUser` на классе с путём до `User` и `groups: ['read', 'update', 'delete']`; кастомного локатора ради доступа нет.
- [ ] Правила, замкнутые на схему, — в секции `constraints`, не атрибутами; ни один предикат не продублирован в обоих слоях.
- [ ] Поля для роли — `HasRole`; неизменяемые — `UnchangedOnUpdate(groups: ['update'])` и связь в `writable`.
- [ ] 403-констрейнты объявлены после 422-правил или гейтятся `When`.
- [ ] Вложенные сущности в DTO — через `GroupSequence` + `Valid(groups)`.
- [ ] Сейвер либо отсутствует (`doctrine`), либо содержит только побочные эффекты.
- [ ] Feature-тест на каждое правило: текст `detail` совпадает с `message`; для правил доступа — на `read`, `update` и `delete` отдельно.
