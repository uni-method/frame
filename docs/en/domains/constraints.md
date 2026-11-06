# Constraints

The `constraints` section and how constraints in general are organized in the
framework: what is described by data, what by code, and how it comes together at runtime.

## Terms and place in the architecture

**A constraint** is a predicate over a _subject_ in a _context_ with
a declared _outcome_. Validation is a special case: subject = an object built from
the client input, outcome = an accumulated violation (422). Other outcomes of the same
nature:

| Outcome     | Code                  | Who                             | Example                                 |
| ----------- | --------------------- | ------------------------------- | --------------------------------------- |
| `violation` | 422                   | accumulated, returned as a list | empty name, email is not an email       |
| `deny`      | 403                   | interrupts, exactly one error   | the object belongs to another user      |
| `missing`   | 404                   | interrupts                      | a reference to a non-existent entity    |
| `reject`    | 400                   | interrupts                      | an undeclared filter, malformed JSON    |
| `misconfig` | prebuild error / 500  | does not reach the client       | the rule is not applicable to the type  |

That is why in naming the framework speaks of **constraints**, not of a validator:
`ConstraintCheckerInterface`, `ConstraintContext`, `ConstraintPhase`
(see "Runtime constraint checking" below).

**The `constraints` section is not part of the working part of the framework.** It is
a language-independent specification read by the **prebuild**: together with `schema`
and `types` it describes the contract, and the prebuild produces from it the working config and
a native check artifact for the target language (currently PHP/Symfony Validator,
later other targets). The framework runtime knows nothing about `constraints` — it knows
only the `ConstraintCheckerInterface` adapter, which delegates to the target's native
validator.

Division of responsibility with other mechanisms:

- **Caster** guarantees the kind of value ("this is an int"). Rules begin where
  Caster ends: "this int is in a range".
- **`writable`** (schema) — whether the field can be written. Rules — what exactly can be
  written.
- **`|null` in the type spec** — whether the value can be absent in storage.
  The `notNull` rule — a prohibition of null on input (usually contextual).
- **`access`** (schema, on an action/path/operator) — a coarse filter "who can
  call the endpoint at all". Constraints are a fine check over a specific object.

## Section structure

```yaml
constraints:
  user: # key — the model type from schema.models
    attributes:
      email: # key — the attribute name from schema
        rules: [email, { type: length, max: 255 }]
        create: [notBlank]
      tags: # string[]
        rules: [{ type: count, max: 10 }]
        each: [{ type: length, max: 30 }]
    relations:
      author: # manyToOne
        create: [notNull]
      orders: # oneToMany
        rules: [{ type: count, max: 100 }]
```

Four groups of rules on a property:

| Group    | When it applies                                         |
| -------- | ------------------------------------------------------- |
| `rules`  | always (base)                                           |
| `create` | **in addition** to the base ones in the create action   |
| `update` | **in addition** to the base ones in the update action   |
| `each`   | to each element of a list type (scalar arrays only)     |

The merge semantics is **addition, not replacement**: in create
`rules + create` are checked. Overriding one rule with different parameters in the base and
contextual group is not needed and not supported.

`each` is forbidden for relations: elements of a `*ToMany` relation are models, the target model
is validated by its own `constraints` block.

## Rule notation

The short form is a bare name, the full one is an object with parameters "flattened" to
the top level (following the example of filter operators):

```yaml
rules:
  - notBlank # without parameters
  - { type: length, min: 2, max: 64 } # with parameters
```

## Rule catalog

The catalog is closed and stored as data in `resources/constraint-rules.json` — the prebuild
and the editor read from it.

| Rule          | Scope      | Applicable to                | Parameters     |
| ------------- | ---------- | ---------------------------- | -------------- |
| `notNull`     | value      | any nullable type; relations | —              |
| `notBlank`    | value      | string                       | —              |
| `length`      | value      | string                       | `min?`, `max?` |
| `regex`       | value      | string                       | `pattern`      |
| `email`       | value      | string                       | —              |
| `url`         | value      | string                       | —              |
| `range`       | value      | int, float, datetime         | `min?`, `max?` |
| `choice`      | value      | string, int, uuid            | `values`       |
| `equals`      | value      | string, int, bool, uuid      | `value`        |
| `count`       | collection | any list type; toMany relations | `min?`, `max?` |
| `uniqueItems` | collection | string[], int[], uuid[]      | —              |

Catalog policy: a rule gets into it only if it is expressible in **all
targets** of the prebuild. A rule of a single target is either an `x-<target>:` extension in
the specification or local code (see "The boundary between the specification and local code").

A typical case of `equals` is consent to data processing: a bool field that on
creation must be `true`:

```yaml
agreedToTerms:
  create: [{ type: equals, value: true }]
```

## Applicability matrix

The legality of a rule on a property is a function of the parsed type
(`TypeSpecParser` → kind / list / nullable) and of the "this is a relation" flag:

- rules of the **value** scope are not put on a list type directly — on the element
  via `each`;
- rules of the **collection** scope require a list type;
- `notNull` is applicable only to nullable types (on a non-nullable one it is tautological);
- a relation is treated as a type: `oneToMany`/`manyToMany` — list,
  `manyToOne`/`oneToOne` — a nullable single value (being required is an explicit
  `notNull`, usually in `create`);
- on relations only rules marked "relations" are allowed (`notNull`, `count`).

A violation of the matrix, a reference to a non-existent model/property, a missing
required parameter are **prebuild** errors (texts — in [config.md](config.md),
"Config validation", "The constraints section"). All errors are collected at once. Such things
do not reach runtime.

## Null semantics

Value rules are applied only to non-null values. If the value is null:
the only thing checked is whether there is `notNull` among the applicable rules.
Therefore `length`/`email` on a nullable field do not require a value — they restrict
it _when it is present_; being required is expressed by a separate `notNull`.

This semantics is fixed at the specification level, not the target: each target must
reproduce it (for Symfony it matches "out of the box" — `Length`, `Email`,
`Range` skip null themselves).

## Registry of named types (`types`)

An optional dictionary section of reusable types. A declared type
is used in an attribute's `type` on a par with built-in ones, including the modifiers
`[]` and `|null`:

```yaml
types:
  order_status: # enum
    kind: string
    enum: [new, paid, shipped]
  money: # a semantic type on top of int
    kind: int
    rules: [{ type: range, min: 0 }]

schema:
  models:
    order:
      attributes:
        status: { type: order_status }
        total: { type: money }
        prices: { type: "money[]" }
```

Semantics:

- `kind` — the stored scalar kind (from the built-in ones); rule applicability
  is inherited from it (`range` on `money` is legal).
- `enum` — a closed set of values; works as an implicit `choice` rule,
  there is no need to repeat it in `constraints`. Other value rules on enum types
  make no sense.
- `rules` — implicit rules of the type, always apply and are **added** to the rules
  of the property; for list types they are applied to each element. The prebuild expands
  them onto each property of this type.
- `choice` remains for a one-off inline set of values; everything
  reusable — via `types`.

Binding a PHP class (backed enum, value object) is the concern of `impl`, not of this section.

## Prebuild and targets

The prebuild reads `schema` + `types` + `constraints` + `impl` and produces:

1. the working framework config (`schema` + `impl`; there is no `constraints` section in it
   anymore);
2. a native check artifact for the target — for PHP this is Symfony
   Validator metadata;
3. (a side benefit of the same specification) JSON Schema / OpenAPI
   (`minLength`, `pattern`, `format: email`, `minimum`, `enum`, `minItems`,
   `uniqueItems` — a one-to-one mapping), client-side validation, DDL
   hints (`length.max` → `varchar(n)`, `notNull` → `NOT NULL`).

### Target PHP / Symfony Validator

Generating attributes on the entity is **not allowed** — it is hand-written code, regeneration would
overwrite it. The required mechanism already exists in Symfony Validator: class metadata
is collected by `LoaderChain` from several sources. Therefore the prebuild artifact is
a separate metadata source (a generated `validation.generated.yaml` or
a custom `LoaderInterface` reading the specification directly; the choice between them
is open), and local `Assert\*` remain attributes on classes. Both sources
are merged into a single set of metadata **without merging code**: the generated file
is never edited by hand, attributes never repeat the specification rules.

The mapping table for Symfony:

| Specification              | Symfony                                    |
| -------------------------- | ------------------------------------------ |
| `notNull`                  | `NotNull`                                  |
| `notBlank`                 | `NotBlank`                                 |
| `length`                   | `Length`                                   |
| `regex`                    | `Regex`                                    |
| `email`                    | `Email`                                    |
| `url`                      | `Url`                                      |
| `range`                    | `Range`                                    |
| `choice`, `types.*.enum`   | `Choice`                                   |
| `equals`                   | `IdenticalTo`                              |
| `count`                    | `Count`                                    |
| `uniqueItems`              | `Unique`                                   |
| `each: [...]`              | `All([...])`                               |
| `types.*.rules`            | expanded onto each property of this type   |
| group `create` / `update`  | validation group `create` / `update`       |
| group `rules`              | `Default`                                  |

The action context is projected into validation groups with fixed names —
this is the bridge between the generated and the local layer (see "Runtime: phases and context" and
[usage/validation-patterns.md](../usage/validation-patterns.md)).

### What is needed from `impl` for generation

Symfony metadata addresses `properties` or `getters`, while impl stores
getter/setter names. The derivation rule: a public property → `properties`, a method
`getX`/`isX` → `getters: x`; if it cannot be derived — an explicit field
`validationTarget` in the impl attribute.

### Conformance

The correctness of a binding is tested not per project but by a conformance suite:
a specification fixture + expected violations, run against each target.
Each target has in its binding documentation a mapping table (like "Target PHP / Symfony Validator") and
a list of deviations from the specification semantics.

## The boundary between the specification and local code

The model is **one contract with two equal sources of rules**: data
(`constraints`) and code (`Assert\*`, your own constraints). Neither of them is "legacy".
What is centralized is not the declaration but what would otherwise diverge: the violation format,
the context, the order, the outcome.

The demarcation rule is not "simple/complex" but **closure over the schema**:

- a rule parameterized only by a type/path from the schema and literals → **data**
  (`constraints`);
- a rule referring to `this`, another property, an expression, the environment
  (the user, the DB, the previous state) → **code**.

For code, `Expression`, `When`, `Callback`, `GroupSequence`, "same garden zone"
chains are the natural form; there is no point in moving them to YAML. Ready-made forms
of code rules — [usage/validation-patterns.md](../usage/validation-patterns.md).

Obligations of both layers:

1. One violation form: path (`source.pointer`), message, outcome.
2. One context: action → validation groups with fixed names.
3. One order: Caster → generated layer → local layer → saver, with
   `GroupSequence` semantics between layers (a non-empty result of a layer
   stops the next one). Code may not guard what the generated layer
   has already guaranteed (`notNull`, `choice`, range).
4. No duplication of a predicate: one rule lives in exactly one layer.
   The prebuild will not catch this — it is a review checklist item.

The price: the specification is self-documenting (editor, OpenAPI), code is not. Either the
action description honestly says "plus domain rules, see the class", or (a separate
decision) `describe(): string` on your own constraints for documentation generation.

## Runtime: phases and context

The framework calls `ConstraintCheckerInterface::check(object, ConstraintContext)`
in three phases (`ConstraintPhase`):

| Phase         | When                                                  | Object                | Actions                                                             |
| ------------- | ----------------------------------------------------- | --------------------- | ------------------------------------------------------------------- |
| `AfterLocate` | the object is obtained by the locator, input is not yet applied | entity from storage | read, update, delete; resolving `reference` in `writable` — planned |
| `AfterInput`  | the object is built from input and has passed `afterInput` mutators | entity / DTO | create, update                                                      |
| `ListQuery`   | the list request DTO is assembled                     | `{Model}ListRequest`  | list                                                                |

`ConstraintContext` carries `modelType`, `actionType`, `phase`, the current
user (`user`), the `id` from the request (read/update/delete) and `original` —
a snapshot of the object before input is applied (only update in `AfterInput`):
a shallow clone of the entity — scalars are reliable, relations are the same instances;
more details in "Runtime constraint checking" below.

The pipeline by action:

- read: locate → `AfterLocate` → include → output
- update: locate → `AfterLocate` → input mapping → `AfterInput` (with `original`)
  → saver
- delete: locate → `AfterLocate` → remover
- create: input mapping → `AfterInput` → saver
- list: query mapping → `ListQuery` → repository

`AfterLocate` is the phase that did not exist before: checking "is this
user allowed this object" was possible only with a custom locator. Now it is
a constraint on the entity class with the action group (example —
[usage/validation-patterns.md](../usage/validation-patterns.md), pattern 7). Two consequences:

- `list` is fundamentally not covered by this phase: post-filtering the selection is
  a leak through pagination and `total`. For lists, ownership is a required filter
  or the repository.
- A denial on reading reveals the existence of the record; if this matters, an
  option "respond with 404 instead of 403" is needed (planned).

The result of `check` is `Error[]` (accumulated `violation`s); interrupting outcomes
(403/404) — via a domain exception from the implementation. The response code is derived from the
`Error`s themselves (all of the same status → that one; otherwise 400).

## Runtime constraint checking (ConstraintCheckerInterface)

The framework does not check business rules itself — it delegates this to your adapter,
but it decides itself **when** and **with what context** to call it.

```php
interface ConstraintCheckerInterface
{
    /** @return array<\UniMethod\Frame\Integration\Error> */
    public function check(object $object, ConstraintContext $context): array;
}
```

An empty array — no violations. A non-empty one — the framework turns it into
`ApiResult::errors(...)`; the HTTP code is derived from the `Error`s themselves (all of the same status
— it is taken, otherwise 400). The adapter throws interrupting outcomes (403/404) as
a domain exception (see [errors.md](errors.md)) — validation is interrupted, the response has exactly one
error.

### Phases

```php
enum ConstraintPhase: string
{
    case AfterLocate = 'afterLocate'; // object from the locator, input not yet applied
    case AfterInput  = 'afterInput';  // object built from input
    case ListQuery   = 'listQuery';   // list request DTO
}
```

Call points by action:

| Action   | `check` calls                                                      |
| -------- | ------------------------------------------------------------------ |
| read     | `AfterLocate` on the found entity                                  |
| update   | `AfterLocate` on the found entity → input mapping → `AfterInput`   |
| delete   | `AfterLocate` on the found entity                                  |
| create   | `AfterInput` on the assembled entity/DTO                           |
| list     | `ListQuery` on `{Model}ListRequest`                                |

`AfterInput` runs after the `afterInput` phase mutators and before `afterValidate`.
A non-empty result of any phase stops the pipeline: if `AfterLocate` fails
in update, input mapping and the saver are not called; if `AfterInput` fails —
the `afterValidate` mutators and the saver.

If the locator returned `null`, `check` is not called — the 404 response is built before
it.

### Context

```php
final readonly class ConstraintContext
{
    public string $modelType;        // model type from schema.models
    public ActionType $actionType;   // create | read | list | update | delete
    public ConstraintPhase $phase;
    public ?UserInterface $user;     // from UserProviderInterface, null without a provider
    public string|int|null $id;      // id from the request (read/update/delete); null for create/list
    public ?object $original;        // shallow clone of the entity before input is applied; only update in AfterInput
}
```

`original` is a shallow `clone` of the found entity made before input
mapping. The "before" scalars and value objects (`DateTime`) in it are reliable; related
objects and collections are the same instances as in the current one (compare them by id,
not by fields). It is an entity, not a DTO: rules read it with the same getters
as the current object (`UnchangedOnUpdate` in the Symfony bundle does exactly that).
It is needed by rules like "the field cannot be changed after creation", "the status transition
is allowed". The mapper then mutates the found object in place — it does not make a second
request to storage.

Factories: `ConstraintContext::afterLocate(...)`, `::afterInput(...)`,
`::listQuery(...)` — they are used by `ApiService`; you do not need to create the context
in your own code.

### Adapter

The adapter is a bridge to your library (Symfony Validator, etc.): you run the object
through its rules, map violations into `Error(status, detail, title?, source?)`.
The context is used to choose rules: the action and the phase are projected into validation
groups with fixed names (the bridge contract — in
[usage/validation-patterns.md](../usage/validation-patterns.md), "Phases and groups").

```php
final class SymfonyConstraintChecker implements ConstraintCheckerInterface
{
    public function __construct(private ValidatorInterface $inner) {}

    public function check(object $object, ConstraintContext $context): array
    {
        $groups = match ($context->phase) {
            ConstraintPhase::AfterLocate => [$context->actionType->value],
            ConstraintPhase::AfterInput  => ['Default', $context->actionType->value],
            ConstraintPhase::ListQuery   => ['Default'],
        };

        $errors = [];
        foreach ($this->inner->validate($object, null, $groups) as $violation) {
            $errors[] = new Error(
                Errors::ENTITY_VALIDATION_ERROR_CODE,
                (string) $violation->getMessage(),
                'Validation errors',
                ['pointer' => $violation->getPropertyPath()],
            );
        }

        return $errors;
    }
}
```

The difference between "return `Error[]`" and "throw a domain exception": return
`Error[]` for expected violations of a specific object (there may be several of them
at once); a domain exception — for interrupting outcomes (access, not found).
A rule that is not applicable to the object (the path does not exist, the value at the path is of the wrong
kind) is a configuration error: throw an exception with the
`FrameConfigurationException` marker rather than silently skipping the check (see [errors.md](errors.md)).

## Planned

Not implemented; described so that new rules fit into the same model rather than
breeding local code where the form repeats on every entity.

- `message` in the rule descriptor (the text is part of the contract) and filling
  `source.pointer` by the adapter.
- Model-level rules: `constraints.<model>.rules` — `oneOf`, `allOrNone`.
- Rules relative to `original` in update: `unchanged`,
  `transition: {from, to}`, `immutableWhen`.
- Rules on relations parameterized by paths: `exists`, `sameParent`,
  `ownedBy: path` (+ `exceptRoles`), `targetState`.
- `unique` / `uniqueBy: [a, b]` instead of saver code or a 500 from an index.
- `delete` preconditions: `noChildren`, prohibition by status.
- Read side: rules on filter values in `config.filters`
  (`rules: [{type: count, max: 50}]`), minimal length for `like`.
- `on: [read, update, delete]` on a rule — an explicit choice of phases; the prebuild expands
  it into groups.
- `AfterLocate` for `reference` in `writable` (inside `MappingToObject`).
- The `denyAs: 404` option.
- `impl.constraintRules: { alias: class }` — only for environment rules
  parameterized by paths; everything expression-like remains local code.

## Checklist

- [ ] Names are object keys; `attributes` and `relations` are separated.
- [ ] Contextual groups do not duplicate base rules (merge is addition).
- [ ] On list attributes: collection rules in `rules`, per-element ones in `each`.
- [ ] `each` is not used on relations.
- [ ] `notNull` — only on nullable types and toOne relations (more often in `create`).
- [ ] Reusable value sets are in `types`, not an in-place `choice`.
- [ ] A rule closed over the schema is in `constraints`; a rule with `this`/environment
      is in code. One predicate — in one layer.
- [ ] The generated artifact is not edited by hand.
- [ ] The prebuild passes: the applicability matrix, references to models/properties,
      required rule parameters, support of the rules by the chosen target.
