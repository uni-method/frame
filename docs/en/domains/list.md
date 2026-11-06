# list requests

A reference on the list action: rules in the config and the generated DTOs. Step-by-step addition of a list action — [usage/list-action.md](../usage/list-action.md).

## The `config` of a list action

`config` is present only on the `list` action and describes three blocks
(**there can be no other keys in the schema section** — an unknown key, including
a typo like `filtres`, is rejected at load; the exception is the legacy entry
`class: null` / `repository: null`, which is allowed and ignored, whereas
non-empty `class`/`repository` in the schema section is an error "must be set in the
impl section"):

- `pagination` — `{ maxSize?: int|null, defaultSize?: int }`. If the block is set but
  `defaultSize` is omitted — `20` by default. If `maxSize` is omitted — `100` by default
  (the value of `PaginationRule::DEFAULT_MAX_SIZE`, matches the constructor
  default). To remove the upper bound of the page size, you must **explicitly**
  specify `maxSize: null` — this is a deliberate action, the absence of the key does not
  remove the limit.
- `sort` — a list of sorting rules. Like `filters`, this is a **whitelist**: a field
  `sort[<path>]` outside the list or a direction other than `asc`/`desc` → 400
  `InvalidListQueryException`; an empty list means that sorting is not available
  to the client.
- `filters` — a list of filtering rules. This is a **whitelist and the single source of
  truth**: at runtime a field is applied only if the path is declared here and
  the operator is in the rule's `operators`; an undeclared path, an operator outside
  the rule or an unknown operator key → 400 `InvalidListQueryException`.
  A declared path that does not resolve in the schema and has no `typeSpec` is
  a config loading error (and if such a config still survived to runtime —
  500 `ListActionMisconfiguredException`).

Sorting rule (`sort[]`):

| Field              | Type                     | Purpose                                                      |
| ------------------ | ------------------------ | ------------------------------------------------------------ |
| `path`             | string (**required**)    | Path to the field (`address.city`).                          |
| `defaultDirection` | `asc`/`desc`             | Direction if the client did not specify one.                 |
| `required`         | bool                     | Whether sorting by the field is required.                    |
| `defaultValue`     | `{ path, direction }`    | Default sorting if the client did not pass sort at all.      |
| `comment`          | string                   | Comment.                                                     |

Filtering rule (`filters[]`):

| Field          | Type                     | Purpose                                                                                                                                             |
| -------------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| `path`         | string (**required**)    | Path to the field.                                                                                                                                  |
| `operators`    | list (**required**)      | Operators (see below).                                                                                                                              |
| `required`     | bool                     | Whether the filter is required.                                                                                                                     |
| `defaultValue` | `{ operator, value }`    | Default filter value.                                                                                                                               |
| `comment`      | string                   | Comment.                                                                                                                                            |
| `typeSpec`     | string                   | Custom field type; if set, the model attribute is not looked up. **Required** if `path` does not resolve to a schema attribute (a virtual repository field). |

An operator in `operators` is either a string (`equal`, `in`, `like`, `range`) or
an object `{ operator, access? }` for restricting access to a specific operator.

**What of this is applied by the framework and what by you.** At runtime the lib uses
only `filters[].path`/`operators`/`required`/`typeSpec` (the whitelist and type
casting in `MappingToListAction`). `pagination`, `sort[].defaultDirection`,
`sort[].required`, `sort[].defaultValue` and `filters[].defaultValue` it does **not
apply** — they are available to the consumer via `Config` (the DTO generator puts
defaults into property initializers, the repository clamps `size` by `maxSize`). At
bootstrap `ConfigValidator::validateListRules` checks only their
consistency:

- `pagination`: `maxSize` > 0 or `null`, `defaultSize` > 0,
  `defaultSize <= maxSize`;
- `sort[]`: `path` and `defaultValue.path` resolve to an attribute (through a chain of
  relations), paths are unique, `defaultDirection` and `defaultValue` are mutually exclusive,
  `required` and `defaultValue` are mutually exclusive;
- `filters[]`: paths are unique, `required` and `defaultValue` are mutually exclusive,
  `defaultValue.operator` is in `operators`, the shape of the value matches
  the operator (`in` — a list, `range` — a list of two, others — a scalar), and if
  the field type is known (`typeSpec` or a model attribute) — the value is cast to it
  by the same `Caster` as requests (for `datetime` — only an absolute ISO 8601).

A violation — `InvalidConfigStructureException` with the path to the field.

### Filter by a whole relation

The `path` of a rule may point not only to an attribute but also to a **whole relation**
(`user`, `owner.office`). The client passes the id of the target model, and the operator DTO
receives the already loaded entity:

```json
{ "path": "user", "operators": ["equal"] }
```

```yaml
- { path: user, operators: [equal] }
```

- The value from the query is parsed as the id of the target model: the type is taken from its
  `id` attribute; `typeSpec` in the rule overrides it in the same way as for
  scalar paths.
- The entity is loaded by the target model's locator — its own
  (`impl.models.<targetModel>.entityLocator`), the read action's, or
  `impl.defaultLocator` (see [models.md](models.md), "Model locator"); for `in` — with one
  `findMany()` if the locator implements `EntityBatchLocatorInterface`. A not found
  id is a knowingly empty selection (200, `[]`, the repository is not called), see
  [usage/list-action.md](../usage/list-action.md).
- Only the `equal` and `in` operators are allowed (for `in` — an array of ids → an array of
  entities). `like`/`range` on a relation is a config loading error.
- The operator DTO property is typed with the target model's entity class
  (`impl.models[<targetModel>].class`) and is named by the general path rule:
  a single-segment path as is (`user` → `user`), a dotted path in the rel form
  (`owner.office` → `relOwnerRelOffice`). See "list actions:
  generated DTOs and their naming" below.

A rule on a relation and a rule on its field are independent and can coexist:
`path: user` (`filter[equal][user]=123`) gives a `user` property of type `?User`,
`path: user.id` (`filter[equal][user][id]=123`) — a `relUserRelId` property of type
`?int`.

```json
{
  "schema": {
    "models": {
      "product": {
        "attributes": {
          "id": { "type": "int" },
          "name": { "type": "string" },
          "active": { "type": "bool" }
        }
      }
    },
    "actions": {
      "product": {
        "list": {
          "config": {
            "pagination": { "maxSize": 100, "defaultSize": 20 },
            "sort": [
              { "path": "name", "defaultDirection": "asc" },
              {
                "path": "createdAt",
                "defaultValue": { "path": "createdAt", "direction": "desc" }
              }
            ],
            "filters": [
              { "path": "name", "operators": ["equal", "like"] },
              { "path": "active", "operators": ["equal"] },
              {
                "path": "categoryId",
                "operators": [
                  { "operator": "in", "access": { "authRequired": true } }
                ],
                "typeSpec": "int[]"
              }
            ]
          }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    product:
      attributes:
        id:
          type: int
        name:
          type: string
        active:
          type: bool
  actions:
    product:
      list:
        config:
          pagination:
            maxSize: 100
            defaultSize: 20
          sort:
            - path: name
              defaultDirection: asc
            - path: createdAt
              defaultValue:
                path: createdAt
                direction: desc
          filters:
            - path: name
              operators: [equal, like]
            - path: active
              operators: [equal]
            - path: categoryId
              operators:
                - operator: in
                  access:
                    authRequired: true
              typeSpec: "int[]"
```

> For boolean fields only the `equal` operator is allowed — see [config.md](config.md), "Config validation".

## list actions: generated DTOs and their naming

For list actions the framework assembles a set of DTOs from query parameters and passes them
to your `EntityCollectionInterface::list`. The DTO classes themselves are **generated** from
the config — you do not write them, but the names follow strict rules, and knowing these
rules is necessary to understand the behavior and write validation.

From the base model name (`{Model}`) the following are generated:

- `{Model}Filter` — the container of filtering operators (the `filter` property in the root
  request object).
- `{Model}Sort` — sorting (the `sort` property).
- `{Model}Pagination` — pagination (the `pagination` property).
- operator classes `{Model}{Operator}` — `{Model}Equal`, `{Model}In`,
  `{Model}Range`, `{Model}Like`, etc. Each carries the fields for which
  this operator is allowed. The set of public properties must correspond one-to-one to
  the rules of `config.filters`: a property without a covering rule and a rule without
  a property are a config bootstrap validation error
  (`ConfigValidator::validateListFilters`), not a silent skip at runtime.

Property names in operator DTOs:

- a local field — as is (`name` → the `name` property);
- a field through a relation — the dotted path is encoded as
  `rel{Segment}Rel{Segment}...` in PascalCase: `relation.city.name` →
  `relRelationRelCityRelName`.
- a whole relation — the name by the same rule as for attributes (`user` →
  `user`, `owner.office` → `relOwnerRelOffice`). The property is typed with
  the **entity class of the target model** (`?User`), not a scalar: the framework
  parses the value as an id, loads the entity with the target model's locator (see
  [models.md](models.md), "Model locator") and puts the object into the DTO before validation. A not found id is the domain
  `EntityNotFoundException` (404).

Pagination reads `page[number]` and `page[size]`; sorting — the direction `asc`/
`desc` on a field; filters — `filter[<operator>][<field>] = value` (for `in`/`range`
the value is an array). For a rule on a whole relation the value is the id of the target model
(`filter[equal][user]=123`), for `in` — an array of ids.

Operator DTOs can extend `TouchableDto` to distinguish "the field was not passed"
from "passed with the value null":

```php
abstract class TouchableDto
{
    public function markAsSet(string $name): void;
    public function isSet(string $name): bool;
}
```

The framework calls `markAsSet` when filling a field from the query; in your validation
(the `ListQuery` phase via `ConstraintCheckerInterface`) use `isSet` to validate only
the fields actually passed and to handle required filters correctly.

### The root ListRequest (required for any filter/sort/pagination)

If at least one `filter`, `sort` or `pagination` is set for a list action in the config,
you **must** create a hand-written root class `{Model}ListRequest`
and specify it in `impl.actions.<model>.list.config.class`. Without it the framework will not
bind query parameters to the DTOs, and `$filter`/`$sort`/`$pagination` will arrive in
`list()`/`countForList()` as `null` — sorting and pagination silently do not work,
and the declared `filters` without a Filter class (or without the `filter` property on the root)
will not pass the config bootstrap validation.

The class aggregates three optional fields; the field names are fixed (`filter`, `sort`,
`pagination`) — the framework distributes `filter[...]`, `sort[...]`,
`page[...]` by them:

```php
class {Model}ListRequest
{
    #[Assert\Valid]
    public ?{Model}Filter $filter = null;

    #[Assert\Valid]
    public ?{Model}Sort $sort = null;

    #[Assert\Valid]
    public ?{Model}Pagination $pagination = null;
}
```

Include only the fields that are actually declared in the config for this action.

**List action checklist:** when adding `filters`/`sort`/`pagination` to the config,
in the same change create `{Model}ListRequest` (the root), `{Model}Filter` +
operator classes for each operator, `{Model}Sort`, `{Model}Pagination`, and
set `config.class` + `config.repository`.
