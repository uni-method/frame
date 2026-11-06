# Adding a list action (collections)

A companion document to [create-dto.md](create-dto.md), but about reading lists: how to declare
a list action in the config, which DTO classes to create, how to write a repository and in
what format the client passes filters/sorting/pagination.

## How it works

The list request pipeline:

1. Query parameters (`filter[...]`, `sort[...]`, `page[...]`) are parsed by
   `MappingToListAction` into a DTO tree rooted at `{Model}ListRequest`.
2. The root object is run through the constraint check
   (`ConstraintCheckerInterface`, the `ListQuery` phase, the `Default` group); when
   integrating with Symfony, a bridge over Symfony Validator is passed into the framework, and
   then `Assert\*` attributes on the DTO work. The context carries the current
   user — a required "only mine" filter is checked right here, not by
   post-filtering the selection.
3. `$request->filter`, `$request->sort`, `$request->pagination` are passed to
   your repository's `EntityCollectionInterface::list()` together with `$included` —
   a list of relation paths for eager loading (the action's `include` ∪ the paths requested
   via `included` that passed the `includable` filter; the same flat
   representation that `EntityLocatorInterface::find` receives); the same `filter` —
   to `countForList()` for the `X-Total-Count` header.
4. The array of entities from `list()` is mapped into the response according to `impl.models`.

The framework **finds DTO classes by a naming convention** — via reflection, without
registration. For filters `config.filters` is a whitelist and the single source of
truth: a field is applied only if the path is declared in `filters` and the operator is
in the rule's `operators`, otherwise the request is rejected with 400
(`InvalidListQueryException`). A mismatch between rules and DTO classes (an error in a class
or property name) does not silently "lose" the filter — it is caught at application startup
by bootstrap validation (`ConfigValidator::validateListFilters`). Sorting
follows the same standard: `config.sort` is a whitelist, a field outside it,
a direction other than `asc`/`desc` (case does not matter) or `sort` not being an object → 400
`InvalidListQueryException`; there is a rule but no property in `{Model}Sort` →
500 `ListActionMisconfiguredException`. There is no partial application: one error
in `sort[...]` rejects the whole request.

Parsing of `filter[...]` lives in one place — `MappingToListAction::parseFilters()`
(returns `list<ParsedFilter{operator, path, rule, value}>` with raw
values). `map()` casts types based on this result, and `ApiService` uses the same
result to check `access` on operators in the extended form — there is no second copy of query
parsing in the lib. When mocking the mapper in tests, `parseFilters` must be delegated
to a real instance, otherwise the access check will see nothing.

## When a list action is not needed: a computed relation

By analogy with "When it is justified" from [create-dto.md](create-dto.md): before
building an action with filters and a separate request from the client, check whether
the required selection is a **slice of a collection of an existing entity**. If
the client always requests the data in the context of a parent ("the last N log records
of an item", "active children"), the right tool is not list but
a getter with a selection via the Criteria API, returned as a relation via `include`:

```php
/**
 * The last 5 checks, from newest to oldest.
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

Config: the relation in `schema.models` (`type: oneToMany`, `targetModel: ...`), the getter
in `impl.models`, the path in the `include` of the parent's action. The client receives the slice in the same
response as the parent — without a second request, without Filter/Sort/Pagination
DTOs and without a collection repository.

What is needed and what to take into account:

- A bidirectional relation is mandatory: `inversedBy` on the owning side, and on
  the parent — a collection with `mappedBy` and initialization in the constructor.
- The getter must return an **array** (`->toArray()`), not a `Collection` —
  otherwise the framework fails on `oneToMany` with
  `Type mismatch ... expected array, got PersistentCollection`.
- On a persistent collection, `matching()` with `setMaxResults` goes to the DB as a separate
  SQL with LIMIT — the whole collection is not loaded, but this is one query **per
  parent** in the list (N light queries per page). For a page of 20–100
  rows this is fine; for thousands it is already an argument in favor of a list action.
- `Criteria::ASC` is deprecated since doctrine/collections 2.2 — use the
  `Order` enum.

A separate list action is justified when the slice is not bound to a single parent:
an end-to-end log with filters on several fields, pagination within the selection,
the output is a screen in its own right.

### An option without a DB relation: relationMutator

If the data for the slice is not among the entity's Doctrine relations (an aggregate, a computed
object, a record from a "foreign" table without an FK), the relation can be filled by a mutator.
The recipe:

1. On the entity — a public property **without ORM mapping**:

```php
public ?CountryBudgetStat $stat = null;
```

2. In `schema.models` the relation is declared as usual, but with a mutator:

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

3. The mutator is registered in `impl.relationMutators` and fills the property before
   serialization (inside — any query: a repository, DBAL, aggregation):

```yaml
relationMutators:
  countryBudgetStat: 'App\Api\RelationMutator\CountryBudgetStatMutator'
```

4. In `impl.models` the getter points to the property: `stat: { getter: stat }`;
   the path is added to the action's `include`.

Typical applications: aggregated statistics on a reference entity
(a `stat` property, the mutator computes sums over related tables) and pulling in
records to which the entity has no FK — only a string identifier
(a change log → a record of the tracked table by `entity_id`).

Choosing between the three tools: there is a Doctrine relation and its slice is needed —
a Criteria getter; there is no relation, but the data is read in the context of the parent —
a relationMutator; the selection is standalone (its own filters/pagination) —
a list action.

## Naming conventions

**The base name** (`{Model}`) is the short class name of the entity from
`impl.models[<type>].class`. For `App\Entity\Proxy` it is `Proxy`, and nothing else —
the model name in the config (`proxy`) plays no role.

**The namespace** is taken from the root class from
`impl.actions.<model>.list.config.class`. All other classes (`Filter`,
operators, `Sort`, `Pagination`) must be **in the same namespace**.

By the base name the following are looked up:

| Class                                                      | Role                                 | Properties                                                               |
| ---------------------------------------------------------- | ------------------------------------ | ------------------------------------------------------------------------ |
| `{Model}ListRequest`*                                      | the root, specified in `config.class` | `filter`, `sort`, `pagination` (the names are fixed)                    |
| `{Model}Filter`                                            | container of operators               | `equal`, `in`, `like`, `range` — lcfirst of the operator name            |
| `{Model}Equal`, `{Model}In`, `{Model}Like`, `{Model}Range` | filter values                        | one field per allowed path                                               |
| `{Model}Sort`                                              | sorting                              | one field per sortable path, type `?string`, values `asc`/`desc`         |
| `{Model}Pagination`                                        | pagination                           | `number`, `size` (int)                                                   |

\* the name of the root class is formally arbitrary (it is specified explicitly), but by
convention it is `{Model}ListRequest`.

**Property names in operator DTOs and in Sort:**

- a local attribute — as is: the path `name` → the property `name`;
- a path through a relation — `rel` + segments in PascalCase joined with `Rel`:
  `country.id` → `relCountryRelId`, `owner.office.name` → `relOwnerRelOfficeRelName`;
- a whole relation — the name by the same rule: `country` → `country`,
  `owner.office` → `relOwnerRelOffice`. The property is typed with the entity class
  of the target model (`?Country`), not a scalar.

This convention is a frequent cause of "the filter/sorting does not work": the framework will not find
a `countryId` property, `relCountryRelId` is needed. For filters such a
mismatch now fails at bootstrap (a rule without a property in the DTO is a config
validation error); for sorting it is still a silent skip.

**The format of query parameters:**

```
page[number]=2&page[size]=50
sort[createdAt]=desc                         # local field
sort[country.iso2]=asc                       # through a relation
filter[equal][enabled]=1                     # equal: scalar
filter[like][url]=example                    # like: scalar (you add LIKE yourself in the repository)
filter[in][status][0]=a&filter[in][status][1]=b   # in: array
filter[range][day][0]=2026-01-01&filter[range][day][1]=2026-01-31
filter[equal][country][id]=5                 # by a relation field: nested brackets
filter[equal][country]=5                     # whole relation: id -> Country entity
```

Nested brackets through relations (`[country][id]`) collapse into the path `country.id`,
which must match the `path` of the rule in the config. A scalar value on a relation
segment (`[country]=5`) is the rule `path: country`: the two rules are independent and
give different DTO properties (`relCountryRelId` and `country`).

## Steps

### 1. Declare the action in `schema.actions`

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

Nuances:

- `filters` is a **whitelist**: you can filter only by declared paths and
  only with operators declared for the path. An undeclared path or an operator outside
  the rule's `operators` → 400 `InvalidListQueryException`. A model attribute or
  a DTO property without a rule is not filterable — the config, not the shape of the DTO, defines
  the public filtering contract.
- The filter's `path` points to an attribute of the **model from the schema** (including through relations);
  the value type is derived from the type of the target model's attribute. If the path does not
  match the schema (a virtual repository field), set `typeSpec` right
  in the rule (`typeSpec: int`). A path that does not resolve in the schema **and** has no
  `typeSpec` is a config loading error (previously this silently degraded
  into skipping the filter).
- `path` may point to a **whole relation** (`country`, `user`): the client
  passes an id, the framework loads the entity with the target model's locator (see
  [domains/models.md](../domains/models.md), "Model locator") and puts the object into the DTO; a not found id is not an error but a knowingly empty selection:
  200 with `[]` and `X-Total-Count: 0`, the repository is not called (see below).
  Operators on a relation — only `equal` and `in`.
- `config: {}` without filters/pagination is valid; then `config.class` is not needed
  and `list()` receives three `null`s.
- `required: true` on a rule makes the framework create the operator object even
  without query parameters — validate being required via `isSet()` (see below).

### 2. Create the DTO classes

All in one namespace; in the project it is customary to use
`App\Repository\Model\{Model}\...`. Properties are public nullable, pagination defaults
are in the initializers.

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

    public ?Country $country = null;       // country, whole relation
}

class ProxyIn
{
    public ?array $relCountryRelId = null; // country.id, in operator
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

Rules:

- Include in `ListRequest` only the fields declared in the action config
  (no sorting — no `sort` property and no `{Model}Sort` class).
- Put into an operator class only the paths allowed for this operator in
  the config. An extra property is a hole: the mapper will fill it if the client sends it.
- The mapper does **not** apply `maxSize`/`defaultSize` from the schema: the default is the
  initializer of the `size` property, the ceiling is enforced by you
  (`#[Assert\Range(max: 100)]` on `size` or clamping in the repository). The same for
  `sort[].defaultDirection/defaultValue/required` and `filters[].defaultValue`:
  the framework only checks their consistency at bootstrap
  (`ConfigValidator::validateListRules`, see [domains/config.md](../domains/config.md)), applying them is
  up to the DTO generator and the repository.
- The config does not describe constraints on **the filter values themselves**: `FilterRule`
  has only `required`, `operators`, `defaultValue`, `typeSpec`, and the
  `constraints` section relates to attributes and relations of models in writing actions.
  The collection size for `in`, a range, a format — with validator attributes right on
  the property of the operator class; the framework runs the assembled root object
  through the validator before calling the repository, so a violation gives 422 and
  the request does not go to the DB:

```php
class ProxyIn
{
    #[Assert\Count(max: 50, maxMessage: "No more than 50 values")]
    #[Assert\All([new Assert\Positive()])]
    public ?array $relCountryRelId = null;
}
```

- "Not passed" can be distinguished from "passed null" by extending the operator DTO
  from `TouchableDto` and checking `isSet('field')` in validation — this is needed for
  `required` filters.

### 3. The repository

Implements `UniMethod\Frame\Persistence\EntityCollectionInterface`. The signatures
accept `?object` — narrow the type first. One private condition builder
is used in both `list()` and `countForList()`, otherwise `X-Total-Count`
will diverge from the output.

`list()` receives `array $included` as its last argument — a flat list of relation
paths that will get into the response (for example `['author', 'tags', 'tags.group']`).
Based on it the repository does eager loading (JOIN + addSelect) so that
`MappingFromObject` does not pull in relations lazily one by one for each entity.
`countForList()` does not receive it.

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

        // sorting: {Model}Sort properties with 'asc'/'desc', otherwise the default
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
        // the same builder + count(id)
    }

    private function getQueryBuilderWithAllFilters(?ProxyFilter $filter): QueryBuilder
    {
        $qb = $this->createQueryBuilder(self::ALIAS);

        if ($filter?->equal?->enabled !== null) {
            $qb->andWhere(self::ALIAS . '.enabled = :eq_enabled')
               ->setParameter('eq_enabled', $filter->equal->enabled);
        }

        // a ManyToOne relation is filtered by the FK without a JOIN
        if ($filter?->equal?->relCountryRelId !== null) {
            $qb->andWhere(self::ALIAS . '.country = :eq_country')
               ->setParameter('eq_country', $filter->equal->relCountryRelId);
        }

        // whole relation: the DTO already holds the entity, the parameter is passed as an object
        if ($filter?->equal?->country !== null) {
            $qb->andWhere(self::ALIAS . '.country = :eq_country_entity')
               ->setParameter('eq_country_entity', $filter->equal->country);
        }

        // like: you add the % wildcards yourself
        if ($filter?->like?->host !== null) {
            $qb->andWhere(self::ALIAS . '.host LIKE :like_host')
               ->setParameter('like_host', '%' . $filter->like->host . '%');
        }

        return $qb;
    }
}
```

### 4. Bind in `impl.actions`

```yaml
proxy:
  list:
    entityLocator: doctrine
    config:
      class: 'App\Repository\Model\Proxy\ProxyListRequest'
      repository: 'App\Repository\ProxyRepository'
```

- `config.repository` is always required (otherwise 500
  `ListActionMisconfiguredException`). The repository itself must be passed to
  `ApiService` in the `repositories` set (in the Symfony bundle — a service implementing
  `EntityCollectionInterface`), otherwise also 500 `ListActionMisconfiguredException`
  with the class name in the text. It is looked up by `instanceof`: Symfony lazy proxies and
  subclasses of the class from the config are suitable; two matching instances —
  `AmbiguousRepositoryException` at startup.
- `config.class` is required as soon as the action's schema has at least one
  `filter`/`sort`/`pagination`. Without it the DTOs are not assembled and the parameters are **silently
  ignored** — the most insidious failure mode.
- `entityLocator` is needed if includes/relations are resolved via the locator.

## Value type conversion

The mapper converts the value by the attribute type from the schema (or the rule's `typeSpec`)
with the same `Caster` as the request body: `int`, `float`, `bool`
(`1/true/on/yes` and `0/false/off/no`), `datetime` (formats — see
[domains/types.md](../domains/types.md)), `string`, `uuid`. A value that cannot be cast to the type
(`qty=abc`, `active=abc`, an invalid uuid) — 400 `InvalidListQueryException`.
For `in`/`range` the value must be an array — otherwise 400
`InvalidListQueryException`. An empty string is cast to `null`; for
a non-nullable type this is 400. A literal empty string can be passed as `''''`
(the wrapping single quotes are removed).

For a rule on a whole relation the value is parsed as the id of the target model (the type —
from its `id` attribute or from the rule's `typeSpec`), then the framework loads the entities
with the target model's locator and puts them into the DTO:

- `in`: ids are deduplicated; if the locator implements
  `EntityBatchLocatorInterface`, a single `findMany(class, ids)` is called, otherwise
  `find()` for each id. Not found ids drop out of the result.
- Not a single entity found (for `equal` — the single id) →
  `UnsatisfiableListFilterException`: `ApiService` responds with 200 and an empty
  list and `X-Total-Count: 0`, without calling the repository and without calling
  `ListQuery` constraints. Outside `ApiService` the exception carries 404.

Implement `EntityBatchLocatorInterface` on the locators of models that are filtered by lists —
otherwise `filter[in][author][]=…` with 50 ids gives 50 queries.

## Pitfalls

- **An undeclared filter field is a 400, not a silent skip.** Previously
  an unknown operator, a missing property in the DTO and a path not from the schema were silently
  ignored and the request was executed **without** the filter (200 with the full selection).
  Now: an undeclared path → `Unknown filter field '...'`, an operator outside
  the rule → `Operator '...' is not allowed for filter field '...'`, an unknown
  operator key → `Unknown filter operator '...'` — all 400
  `InvalidListQueryException`. Only empty nested
  structures (`filter[equal][owner]` without a value) and a rule without a property in the
  DTO are silently skipped — but the latter does not survive to runtime: it is rejected by bootstrap validation.
- **A declared path that does not resolve is a 500.** If the path is in
  `filters` but does not match the schema and has no `typeSpec`, at runtime this is
  `ListActionMisconfiguredException` (a misconfiguration, not a client error);
  normally such a config will not load at all (see the bootstrap validation in
  [domains/config.md](../domains/config.md), "Post-validations").
- **`{Model}` is the entity class name, not the model name.** A `stream` model over
  the `Stream` class gives `StreamFilter`; a `sub` model over `OneWinSub` —
  `OneWinSubFilter`.
- **DTOs are looked up in the namespace of the root class.** Spread Filter/Sort/Pagination
  over other folders — the mapper will not find them (for filters bootstrap will catch it;
  for sort — 500 `ListActionMisconfiguredException` on the first request with
  `sort[...]` or with a required rule; for pagination — still a silent
  skip).
- **`$sort` arrives as an object only if the client passed `sort[...]` or
  the schema has a required rule.** The repository must have a default sorting
  for the `null` case. Fields outside `config.sort` → 400, as for filters.
- **400 versus 403 on operators.** An operator not declared in the rule's `operators`
  is a 400 `InvalidListQueryException` at the mapping stage. 403
  `ForbiddenOperatorException` remains for the extended form
  `{ operator, access }`: the operator is declared, but the current user lacks
  the rights.
- **The `access` check on an operator expects a different query form.** It reads
  `filter[<path>][operator]`, that is, it triggers on
  `?filter[status][operator]=in&filter[status][value][]=active`, but not on the main
  mapper format (`?filter[in][status][]=active`). While this is so, do not
  rely on `{ operator, access }` as the only protection of an operator:
  duplicate the restriction via the `access` of the whole action or a check in
  the repository.
- **`required` + the extended operator form.** The operator name of
  `FilterOperatorConfig` lives in the nested enum; if a rule is both
  `required` and declared via `{ operator, access }`, the operator object
  is created by the nested name — when modifying this logic do not take `name`
  from `FilterOperatorConfig` itself.
- **`user` and `user.id` are different filters.** The first accepts an id, puts the
  entity into the DTO (`?User $user`) and gives an empty selection for a not found id; the second remains
  scalar (`?int $relUserRelId`) and says nothing about the existence of the record.
  Declare both only if both are needed in the contract.
- **like is not escaped** — `%`/`_` in user input will get into the pattern
  as metacharacters; escape them in the repository if this matters.

## Migrating to the strict filter mode

The strict mode is the default behavior, not an option. Existing configs that
filtered by schema attributes **without** rules in `filters` (previously a rule was
only a donor of `typeSpec`) require an explicit rule for each filterable path with
the full list of operators. There is no need to look for missed places —
bootstrap validation will point to each one: a public property of an operator DTO class
without a covering rule is a config loading error (`Property '...' has no
corresponding rule in 'filters' ...`). Clients that relied on undeclared
fields will start getting 400 instead of an unfiltered 200 — this is exactly the bug
fix, but API consumers should be warned about the contract change.

## Checklist

- [ ] It is verified that list is needed at all: a slice of a parent's collection is returned
      by a computed relation (Criteria getter + `include`), not by a separate action.
- [ ] `schema.actions.<model>.list`: `access`, `config.pagination`,
      `config.sort`, `config.filters` (path/operators/typeSpec/required),
      `include`.
- [ ] DTOs in one namespace: `{Model}ListRequest` (fields strictly `filter`,
      `sort`, `pagination` with `#[Assert\Valid]`), `{Model}Filter`
      (fields `equal`/`in`/`like`/`range`), operator classes, `{Model}Sort`,
      `{Model}Pagination` (`number = 1`, `size = defaultSize`).
- [ ] Paths through relations in the DTO are named `rel{Segment}Rel{Segment}`; rules on a whole
      relation — a property by the same rule (`user`, `relOwnerRelOffice`) of the type of
      the target model's entity class, and this model has a locator: its own
      `entityLocator`, a read action with `entityLocator`, or `impl.defaultLocator`.
- [ ] Each filterable path is declared in `config.filters` with the full list of
      operators; each public property of operator classes is covered by
      a rule (reverse drift is a bootstrap error).
- [ ] `maxSize` is enforced (an Assert on `size` or clamping in the repository).
- [ ] Constraints on filter values (the size of the `in` array, ranges,
      formats) — validator attributes on the properties of operator classes;
      there are no such settings in the config.
- [ ] Repository: `implements EntityCollectionInterface`, input type
      checks, a common builder for `list`/`countForList`, handling `$sort` +
      default sorting, pagination via offset/limit.
- [ ] `impl.actions.<model>.list`: `config.class` + `config.repository`;
      `filters`/`sort`/`pagination` — only in the schema section (in the impl section
      this is a loading error).
- [ ] The config passes bootstrap validation
      (`ConfigValidator::validateListFilters`): paths resolve or have
      `typeSpec`, operator classes and DTO properties are consistent with the rules.
