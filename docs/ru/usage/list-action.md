# Добавление list-действия (коллекции)

Парный документ к [create-dto.md](create-dto.md), но про чтение списков: как объявить
list-действие в конфиге, какие DTO-классы создать, как написать репозиторий и в
каком формате клиент передаёт фильтры/сортировку/пагинацию.

## Как это работает

Конвейер list-запроса:

1. Query-параметры (`filter[...]`, `sort[...]`, `page[...]`) разбираются
   `MappingToListAction` в дерево DTO с корнем `{Model}ListRequest`.
2. Корневой объект прогоняется через проверку ограничений
   (`ConstraintCheckerInterface`, фаза `ListQuery`, группа `Default`); при
   интеграции с Symfony в фреймворк прокидывается мост над Symfony Validator, и
   тогда работают атрибуты `Assert\*` на DTO. Контекст несёт текущего
   пользователя — обязательный фильтр «только свои» проверяется здесь же, а не
   пост-фильтрацией выборки.
3. `$request->filter`, `$request->sort`, `$request->pagination` передаются в
   `EntityCollectionInterface::list()` вашего репозитория вместе с `$included` —
   списком путей связей для eager loading (`include` действия ∪ запрошенные
   через `included` пути, прошедшие фильтр `includable`; то же плоское
   представление, что получает `EntityLocatorInterface::find`); тот же `filter` —
   в `countForList()` для заголовка `X-Total-Count`.
4. Массив сущностей из `list()` маппится в ответ по `impl.models`.

Классы DTO фреймворк **находит по соглашению об именах** — рефлексией, без
регистрации. Для фильтров `config.filters` — вайтлист и единственный источник
истины: поле применяется только если путь заявлен в `filters` и оператор входит
в `operators` правила, иначе запрос отклоняется с 400
(`InvalidListQueryException`). Рассинхрон правил и DTO-классов (ошибка в имени
класса или свойства) не «теряет» фильтр молча — он ловится на старте приложения
bootstrap-валидацией (`ConfigValidator::validateListFilters`). Сортировка
подчиняется тому же стандарту: `config.sort` — вайтлист, поле вне него,
направление не `asc`/`desc` (регистр не важен) или `sort` не объектом → 400
`InvalidListQueryException`; правило есть, а свойства в `{Model}Sort` нет →
500 `ListActionMisconfiguredException`. Частичного применения нет: одна ошибка
в `sort[...]` отклоняет весь запрос.

Разбор `filter[...]` живёт в одном месте — `MappingToListAction::parseFilters()`
(возвращает `list<ParsedFilter{operator, path, rule, value}>` с сырыми
значениями). `map()` приводит типы по этому результату, а `ApiService` по нему
же проверяет `access` на операторах расширенной формы — второй копии разбора
query в либе нет. При моках маппера в тестах `parseFilters` нужно делегировать
реальному экземпляру, иначе проверка access ничего не увидит.

## Когда list-действие не нужно: вычисляемая связь

По аналогии с «Когда это обосновано» из [create-dto.md](create-dto.md): прежде чем
городить экшен с фильтрами и отдельным запросом с клиента, проверьте, не
является ли нужная выборка **срезом коллекции существующей сущности**. Если
клиент всегда запрашивает данные в контексте родителя («последние N записей
журнала у элемента», «активные дочерние»), правильный инструмент — не list, а
геттер с выборкой через Criteria API, отданный как связь через `include`:

```php
/**
 * Последние 5 проверок, от новых к старым.
 *
 * @return array<CheckResult>
 */
public function getLastCheckResultsAsArray(): array
{
    return $this->checkResults
        ->matching(
            Criteria::create()
                ->orderBy(['checkedAt' => Order::Descending])
                ->setMaxResults(5),
        )
        ->toArray();
}
```

Конфиг: связь в `schema.models` (`type: oneToMany`, `targetModel: ...`), геттер
в `impl.models`, путь в `include` действия родителя. Клиент получает срез в том
же ответе, что и родителя, — без второго запроса, без Filter/Sort/Pagination
DTO и без репозитория-коллекции.

Что нужно и что учесть:

- Двунаправленная связь обязательна: на владеющей стороне `inversedBy`, на
  родителе — коллекция с `mappedBy` и инициализацией в конструкторе.
- Геттер обязан возвращать **массив** (`->toArray()`), не `Collection` —
  фреймворк на `oneToMany` иначе падает с
  `Type mismatch ... expected array, got PersistentCollection`.
- На persistent-коллекции `matching()` с `setMaxResults` уходит в БД отдельным
  SQL с LIMIT — вся коллекция не грузится, но это по запросу **на каждого
  родителя** в списке (N лёгких запросов на страницу). Для страницы в 20–100
  строк — нормально; для тысяч — уже аргумент в пользу list-действия.
- `Criteria::ASC` устарел начиная с doctrine/collections 2.2 — используйте
  enum `Order`.

Отдельное list-действие оправдано, когда срез не привязан к одному родителю:
сквозной журнал с фильтрами по нескольким полям, пагинация внутри выборки,
выдача сама по себе является экраном.

### Вариант без связи в БД: relationMutator

Если данных для среза нет среди Doctrine-связей сущности (агрегат, вычисляемый
объект, запись из «чужой» таблицы без FK) — связь можно заполнить мутатором.
Рецепт:

1. На сущности — публичное свойство **без ORM-маппинга**:

```php
public ?CountryBudgetStat $stat = null;
```

2. В `schema.models` связь объявляется как обычно, но с мутатором:

```yaml
country:
  relations:
    stat:
      type: oneToOne
      targetModel: countryBudgetStat
      mutators:
        beforeOutput:
          - countryBudgetStat
```

3. Мутатор регистрируется в `impl.relationMutators` и заполняет свойство перед
   сериализацией (внутри — любой запрос: репозиторий, DBAL, агрегация):

```yaml
relationMutators:
  countryBudgetStat: 'App\Api\RelationMutator\CountryBudgetStatMutator'
```

4. В `impl.models` геттер указывает на свойство: `stat: { getter: stat }`;
   путь добавляется в `include` действия.

Типовые применения: агрегированная статистика на справочной сущности
(`stat`-свойство, мутатор считает суммы по связанным таблицам) и подтягивание
записей, на которые у сущности нет FK — только строковый идентификатор
(журнал изменений → запись отслеживаемой таблицы по `entity_id`).

Выбор между тремя инструментами: есть Doctrine-связь и нужен её срез —
Criteria-геттер; связи нет, но данные читаются в контексте родителя —
relationMutator; выборка самостоятельна (свои фильтры/пагинация) —
list-действие.

## Соглашения об именовании

**Базовое имя** (`{Model}`) — короткое имя класса сущности из
`impl.models[<type>].class`. Для `App\Entity\Proxy` это `Proxy`, и никак иначе —
имя модели в конфиге (`proxy`) роли не играет.

**Namespace** — берётся у корневого класса из
`impl.actions.<model>.list.config.class`. Все остальные классы (`Filter`,
операторы, `Sort`, `Pagination`) должны лежать **в том же namespace**.

По базовому имени ищутся:

| Класс                                                      | Роль                                 | Свойства                                                                 |
| ---------------------------------------------------------- | ------------------------------------ | ------------------------------------------------------------------------ |
| `{Model}ListRequest`*                                      | корень, указывается в `config.class` | `filter`, `sort`, `pagination` (имена фиксированы)                       |
| `{Model}Filter`                                            | контейнер операторов                 | `equal`, `in`, `like`, `range` — lcfirst имени оператора                 |
| `{Model}Equal`, `{Model}In`, `{Model}Like`, `{Model}Range` | значения фильтров                    | по полю на каждый разрешённый путь                                       |
| `{Model}Sort`                                              | сортировка                           | по полю на каждый сортируемый путь, тип `?string`, значения `asc`/`desc` |
| `{Model}Pagination`                                        | пагинация                            | `number`, `size` (int)                                                   |

\* имя корневого класса формально произвольное (он указан явно), но по
конвенции — `{Model}ListRequest`.

**Имена свойств в операторных DTO и в Sort:**

- локальный атрибут — как есть: путь `name` → свойство `name`;
- путь по связи — `rel` + сегменты в PascalCase через `Rel`:
  `country.id` → `relCountryRelId`, `owner.office.name` → `relOwnerRelOfficeRelName`;
- связь целиком — имя по тому же правилу: `country` → `country`,
  `owner.office` → `relOwnerRelOffice`. Свойство типизировано entity-классом
  целевой модели (`?Country`), а не скаляром.

Это соглашение — частая причина «фильтр/сортировка не работает»: свойство
`countryId` фреймворк не найдёт, нужно `relCountryRelId`. Для фильтров такое
расхождение теперь падает на bootstrap (правило без свойства в DTO — ошибка
валидации конфига); для сортировки — по-прежнему тихий пропуск.

**Формат query-параметров:**

```
page[number]=2&page[size]=50
sort[createdAt]=desc                         # локальное поле
sort[country.iso2]=asc                       # по связи
filter[equal][enabled]=1                     # equal: скаляр
filter[like][url]=example                    # like: скаляр (LIKE добавляете сами в репозитории)
filter[in][status][0]=a&filter[in][status][1]=b   # in: массив
filter[range][day][0]=2026-01-01&filter[range][day][1]=2026-01-31
filter[equal][country][id]=5                 # по полю связи: вложенные скобки
filter[equal][country]=5                     # связь целиком: id -> сущность Country
```

Вложенные скобки по связям (`[country][id]`) схлопываются в путь `country.id`,
он должен совпадать с `path` правила в конфиге. Скалярное значение на сегменте
связи (`[country]=5`) — это правило `path: country`: два правила независимы и
дают разные свойства DTO (`relCountryRelId` и `country`).

## Шаги

### 1. Объявить действие в `schema.actions`

```yaml
proxy:
  list:
    access:
      authRequired: true
      roles: ["ROLE_ADMIN"]
    config:
      pagination: { maxSize: 100, defaultSize: 20 }
      sort:
        - { path: host, defaultDirection: asc }
      filters:
        - { path: enabled, operators: [equal] }
        - { path: country, operators: [equal] }
        - { path: country.id, operators: [equal, in] }
        - { path: host, operators: [like] }
    include:
      - country
```

Нюансы:

- `filters` — **вайтлист**: фильтровать можно только заявленными путями и
  только заявленными для пути операторами. Незаявленный путь или оператор вне
  `operators` правила → 400 `InvalidListQueryException`. Атрибут модели или
  свойство DTO без правила нефильтруемы — конфиг, а не форма DTO, определяет
  публичный контракт фильтрации.
- `path` фильтра указывает атрибут **модели из схемы** (в т.ч. по связям);
  тип значения выводится из типа атрибута целевой модели. Если путь не
  бьётся со схемой (виртуальное поле репозитория) — задайте `typeSpec` прямо
  в правиле (`typeSpec: int`). Путь, который не резолвится в схему **и** не
  имеет `typeSpec`, — ошибка загрузки конфига (раньше это тихо деградировало
  в пропуск фильтра).
- `path` может указывать на **связь целиком** (`country`, `user`): клиент
  передаёт id, фреймворк грузит сущность локатором целевой модели (см.
  [domains/models.md](../domains/models.md), «Локатор модели») и кладёт объект в DTO; ненайденный id — не ошибка, а заведомо пустая выборка:
  200 с `[]` и `X-Total-Count: 0`, репозиторий не вызывается (см. ниже).
  Операторы на связи — только `equal` и `in`.
- `config: {}` без фильтров/пагинации — валидно; тогда `config.class` не нужен
  и в `list()` придут три `null`.
- `required: true` у правила заставит фреймворк создать объект оператора даже
  без query-параметров — валидируйте обязательность через `isSet()` (см. ниже).

### 2. Создать DTO-классы

Все в одном namespace; в проекте принято
`App\Repository\Model\{Model}\...`. Свойства публичные nullable, дефолты
пагинации — в инициализаторах.

```php
class ProxyListRequest
{
    #[Assert\Valid]
    public ?ProxyFilter $filter = null;

    #[Assert\Valid]
    public ?ProxySort $sort = null;

    #[Assert\Valid]
    public ?ProxyPagination $pagination = null;
}

class ProxyFilter
{
    #[Assert\Valid]
    public ?ProxyEqual $equal = null;

    #[Assert\Valid]
    public ?ProxyIn $in = null;

    #[Assert\Valid]
    public ?ProxyLike $like = null;
}

class ProxyEqual
{
    public ?bool $enabled = null;

    public ?int $relCountryRelId = null;   // country.id

    public ?Country $country = null;       // country, связь целиком
}

class ProxyIn
{
    public ?array $relCountryRelId = null; // country.id, оператор in
}

class ProxyLike
{
    public ?string $host = null;
}

class ProxySort
{
    public ?string $host = null;           // 'asc' | 'desc'
}

class ProxyPagination
{
    public int $number = 1;
    public int $size = 20;
}
```

Правила:

- Включайте в `ListRequest` только те поля, что объявлены в конфиге действия
  (нет сортировки — нет свойства `sort` и класса `{Model}Sort`).
- В операторный класс кладите только пути, разрешённые этому оператору в
  конфиге. Лишнее свойство — дыра: маппер заполнит его, если клиент пришлёт.
- `maxSize`/`defaultSize` из схемы маппер **не** применяет: дефолт — это
  инициализатор свойства `size`, потолок enforce'ится вами
  (`#[Assert\Range(max: 100)]` на `size` или клампом в репозитории). То же для
  `sort[].defaultDirection/defaultValue/required` и `filters[].defaultValue`:
  фреймворк лишь проверяет их согласованность на bootstrap
  (`ConfigValidator::validateListRules`, см. [domains/config.md](../domains/config.md)), применение —
  за генератором DTO и репозиторием.
- Ограничения на **сами значения фильтра** конфиг не описывает: у `FilterRule`
  есть только `required`, `operators`, `defaultValue`, `typeSpec`, а секция
  `constraints` относится к атрибутам и связям моделей в пишущих действиях.
  Размер коллекции для `in`, диапазон, формат — атрибутами валидатора прямо на
  свойстве операторного класса; фреймворк прогоняет собранный корневой объект
  через валидатор до обращения к репозиторию, поэтому нарушение даёт 422 и
  запрос в БД не уходит:

```php
class ProxyIn
{
    #[Assert\Count(max: 50, maxMessage: "Не больше 50 значений")]
    #[Assert\All([new Assert\Positive()])]
    public ?array $relCountryRelId = null;
}
```

- Отличить «не передано» от «передан null» можно, отнаследовав операторный DTO
  от `TouchableDto` и проверяя `isSet('field')` в валидации — нужно для
  `required`-фильтров.

### 3. Репозиторий

Реализует `UniMethod\Frame\Persistence\EntityCollectionInterface`. Сигнатуры
принимают `?object` — первым делом сузьте тип. Один приватный билдер условий
используется и в `list()`, и в `countForList()`, иначе `X-Total-Count`
разойдётся с выдачей.

`list()` последним аргументом получает `array $included` — плоский список путей
связей, которые попадут в ответ (например `['author', 'tags', 'tags.group']`).
По нему репозиторий делает eager loading (JOIN + addSelect), чтобы
`MappingFromObject` не подтягивал связи лениво по одной на каждую сущность.
`countForList()` его не получает.

```php
class ProxyRepository extends ServiceEntityRepository implements EntityCollectionInterface
{
    private const string ALIAS = 'proxy';

    public function list(
        ?object $filter = null,
        ?object $sort = null,
        ?object $pagination = null,
        array $included = [],
    ): array {
        if ($filter !== null && !$filter instanceof ProxyFilter) {
            throw new InvalidArgumentException(...);
        }
        if ($pagination !== null && !$pagination instanceof ProxyPagination) {
            throw new InvalidArgumentException(...);
        }

        $qb = $this->getQueryBuilderWithAllFilters($filter);

        // сортировка: свойства {Model}Sort с 'asc'/'desc', иначе дефолт
        $applied = false;
        if ($sort instanceof ProxySort && $sort->host !== null) {
            $qb->orderBy(self::ALIAS . '.host', strtoupper($sort->host));
            $applied = true;
        }
        if (!$applied) {
            $qb->orderBy(self::ALIAS . '.host', 'ASC');
        }

        if ($pagination instanceof ProxyPagination) {
            $qb->setFirstResult(($pagination->number - 1) * $pagination->size)
               ->setMaxResults($pagination->size);
        }

        return $qb->getQuery()->getResult();
    }

    public function countForList(?object $filter = null): int
    {
        // тот же билдер + count(id)
    }

    private function getQueryBuilderWithAllFilters(?ProxyFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder(self::ALIAS);

        if ($filter?->equal?->enabled !== null) {
            $qb->andWhere(self::ALIAS . '.enabled = :eq_enabled')
               ->setParameter('eq_enabled', $filter->equal->enabled);
        }

        // связь ManyToOne фильтруется по FK без JOIN
        if ($filter?->equal?->relCountryRelId !== null) {
            $qb->andWhere(self::ALIAS . '.country = :eq_country')
               ->setParameter('eq_country', $filter->equal->relCountryRelId);
        }

        // связь целиком: в DTO уже сущность, параметр передаётся объектом
        if ($filter?->equal?->country !== null) {
            $qb->andWhere(self::ALIAS . '.country = :eq_country_entity')
               ->setParameter('eq_country_entity', $filter->equal->country);
        }

        // like: подстановку % добавляете сами
        if ($filter?->like?->host !== null) {
            $qb->andWhere(self::ALIAS . '.host LIKE :like_host')
               ->setParameter('like_host', '%' . $filter->like->host . '%');
        }

        return $qb;
    }
}
```

### 4. Привязать в `impl.actions`

```yaml
proxy:
  list:
    entityLocator: doctrine
    config:
      class: 'App\Repository\Model\Proxy\ProxyListRequest'
      repository: 'App\Repository\ProxyRepository'
```

- `config.repository` обязателен всегда (иначе 500
  `ListActionMisconfiguredException`). Сам репозиторий должен быть передан в
  `ApiService` в наборе `repositories` (в Symfony-бандле — сервис, реализующий
  `EntityCollectionInterface`), иначе тоже 500 `ListActionMisconfiguredException`
  с именем класса в тексте. Ищется по `instanceof`: lazy-прокси Symfony и
  наследники класса из конфига подходят; два подходящих экземпляра — 
  `AmbiguousRepositoryException` на старте.
- `config.class` обязателен, как только в схеме действия есть хоть один
  `filter`/`sort`/`pagination`. Без него DTO не собираются и параметры **молча
  игнорируются** — самый коварный режим отказа.
- `entityLocator` нужен, если include/связи резолвятся через локатор.

## Преобразование типов значений

Маппер конвертирует значение по типу атрибута из схемы (или `typeSpec`
правила) тем же `Caster`, что и тело запроса: `int`, `float`, `bool`
(`1/true/on/yes` и `0/false/off/no`), `datetime` (форматы — см.
[domains/types.md](../domains/types.md)), `string`, `uuid`. Значение, которое не приводится к типу
(`qty=abc`, `active=abc`, невалидный uuid), — 400 `InvalidListQueryException`.
Для `in`/`range` значение обязано быть массивом — иначе 400
`InvalidListQueryException`. Пустая строка приводится к `null`; для
non-nullable типа это 400. Буквальную пустую строку можно передать как `''''`
(обёртка одинарными кавычками снимается).

Для правила по связи целиком значение разбирается как id целевой модели (тип —
из её атрибута `id` либо из `typeSpec` правила), затем фреймворк грузит сущности
локатором целевой модели и кладёт их в DTO:

- `in`: id дедуплицируются; если локатор реализует
  `EntityBatchLocatorInterface`, вызывается один `findMany(class, ids)`, иначе
  `find()` на каждый id. Ненайденные id из результата выпадают.
- Ни одной найденной сущности (для `equal` — единственный id) →
  `UnsatisfiableListFilterException`: `ApiService` отвечает 200 с пустым
  списком и `X-Total-Count: 0`, не обращаясь к репозиторию и не вызывая
  `ListQuery`-констрейнты. Вне `ApiService` исключение несёт 404.

Реализуйте `EntityBatchLocatorInterface` на локаторах моделей, по которым
фильтруют списками, — иначе `filter[in][author][]=…` из 50 id даёт 50 запросов.

## Подводные камни

- **Незаявленное поле фильтра — это 400, а не тихий пропуск.** Раньше
  неизвестный оператор, отсутствующее свойство в DTO и путь не из схемы молча
  игнорировались и запрос выполнялся **без** фильтра (200 с полной выборкой).
  Теперь: незаявленный путь → `Unknown filter field '...'`, оператор вне
  правила → `Operator '...' is not allowed for filter field '...'`, неизвестный
  ключ оператора → `Unknown filter operator '...'` — всё 400
  `InvalidListQueryException`. Молча пропускаются только пустые вложенные
  структуры (`filter[equal][owner]` без значения) и правило без свойства в
  DTO — но последнее не доживает до рантайма: его отклоняет bootstrap-валидация.
- **Заявленный путь, который не резолвится, — 500.** Если путь есть в
  `filters`, но не бьётся со схемой и не имеет `typeSpec`, в рантайме это
  `ListActionMisconfiguredException` (мисконфигурация, не клиентская ошибка);
  нормально такой конфиг вообще не загрузится (см. bootstrap-валидацию в
  [domains/config.md](../domains/config.md), «Пост-валидации»).
- **`{Model}` — это имя класса сущности, не имя модели.** Модель `stream` над
  классом `Stream` даст `StreamFilter`; модель `sub` над `OneWinSub` —
  `OneWinSubFilter`.
- **DTO ищутся в namespace корневого класса.** Разложите Filter/Sort/Pagination
  по другим папкам — маппер их не найдёт (для фильтров это поймает bootstrap;
  для sort — 500 `ListActionMisconfiguredException` на первом запросе с
  `sort[...]` или с required-правилом; для pagination — по-прежнему тихий
  пропуск).
- **`$sort` приходит объектом, только если клиент передал `sort[...]` или в
  схеме есть required-правило.** Репозиторий обязан иметь дефолтную сортировку
  на случай `null`. Поля вне `config.sort` → 400, как и для фильтров.
- **400 против 403 на операторах.** Оператор, не заявленный в `operators`
  правила, — 400 `InvalidListQueryException` на этапе маппинга. 403
  `ForbiddenOperatorException` остаётся за расширенной формой
  `{ operator, access }`: оператор заявлен, но текущему пользователю не хватает
  прав.
- **Проверка `access` на операторе ждёт другую форму query.** Она читает
  `filter[<path>][operator]`, то есть срабатывает на
  `?filter[status][operator]=in&filter[status][value][]=active`, а на основном
  формате маппера (`?filter[in][status][]=active`) — нет. Пока это так, не
  полагайтесь на `{ operator, access }` как на единственную защиту оператора:
  дублируйте ограничение через `access` всего действия или проверку в
  репозитории.
- **`required` + расширенная форма оператора.** Имя оператора у
  `FilterOperatorConfig` лежит во вложенном enum; если правило одновременно
  `required` и объявлено через `{ operator, access }`, объект оператора
  создаётся по вложенному имени — при доработках этой логики не берите `name`
  с самого `FilterOperatorConfig`.
- **`user` и `user.id` — разные фильтры.** Первый принимает id, отдаёт в DTO
  сущность (`?User $user`) и на ненайденном id даёт пустую выборку; второй остаётся
  скалярным (`?int $relUserRelId`) и о существовании записи ничего не говорит.
  Объявляйте оба, только если оба нужны в контракте.
- **like не экранируется** — `%`/`_` в пользовательском вводе попадут в шаблон
  как метасимволы; экранируйте в репозитории, если это важно.

## Миграция на строгий режим фильтров

Строгий режим — поведение по умолчанию, не опция. Существующие конфиги, где
фильтровали атрибутами схемы **без** правил в `filters` (раньше правило было
лишь донором `typeSpec`), требуют явного правила на каждый фильтруемый путь с
полным перечнем операторов. Пропущенные места искать не нужно —
bootstrap-валидация укажет каждое: публичное свойство операторного DTO-класса
без покрывающего правила — ошибка загрузки конфига (`Property '...' has no
corresponding rule in 'filters' ...`). Клиенты, полагавшиеся на незаявленные
поля, начнут получать 400 вместо нефильтрованного 200 — это и есть исправление
бага, но об изменении контракта стоит предупредить потребителей API.

## Чеклист

- [ ] Проверено, что list вообще нужен: срез коллекции родителя отдаётся
      вычисляемой связью (Criteria-геттер + `include`), а не отдельным экшеном.
- [ ] `schema.actions.<model>.list`: `access`, `config.pagination`,
      `config.sort`, `config.filters` (path/operators/typeSpec/required),
      `include`.
- [ ] DTO в одном namespace: `{Model}ListRequest` (поля строго `filter`,
      `sort`, `pagination` с `#[Assert\Valid]`), `{Model}Filter`
      (поля `equal`/`in`/`like`/`range`), операторные классы, `{Model}Sort`,
      `{Model}Pagination` (`number = 1`, `size = defaultSize`).
- [ ] Пути по связям в DTO названы `rel{Segment}Rel{Segment}`; правила по связи
      целиком — свойство по тому же правилу (`user`, `relOwnerRelOffice`) типа
      entity-класса целевой модели, и у этой модели есть локатор: свой
      `entityLocator`, read-действие с `entityLocator` или `impl.defaultLocator`.
- [ ] Каждый фильтруемый путь заявлен в `config.filters` с полным перечнем
      операторов; каждое публичное свойство операторных классов покрыто
      правилом (обратный дрейф — ошибка bootstrap).
- [ ] `maxSize` enforce'ится (Assert на `size` или кламп в репозитории).
- [ ] Ограничения на значения фильтров (размер `in`-массива, диапазоны,
      форматы) — атрибутами валидатора на свойствах операторных классов;
      в конфиге таких настроек нет.
- [ ] Репозиторий: `implements EntityCollectionInterface`, проверка типов
      входа, общий билдер для `list`/`countForList`, обработка `$sort` +
      дефолтная сортировка, пагинация через offset/limit.
- [ ] `impl.actions.<model>.list`: `config.class` + `config.repository`;
      `filters`/`sort`/`pagination` — только в schema-секции (в impl-секции
      это ошибка загрузки).
- [ ] Конфиг проходит bootstrap-валидацию
      (`ConfigValidator::validateListFilters`): пути резолвятся или имеют
      `typeSpec`, классы операторов и свойства DTO согласованы с правилами.
