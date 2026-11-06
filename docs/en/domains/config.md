# Config

A description of the configuration of the `UniMethod\Frame` framework: how to write a config, how to
generate it for a task and how to check a finished one. All examples are given in pairs —
first JSON, then the equivalent YAML.

The config is loaded by one of the loaders:

- `UniMethod\Frame\Config\JsonConfigLoader` — `load(path)` / `loadFromString(str)`.
- `UniMethod\SymfonyLib\Config\YamlConfigLoader` — the same API, the Symfony Yaml parser.

Both extend `AbstractConfigLoader` and use the same logic of parsing,
validation and assembly. **The data structure for JSON and YAML is identical** — only
the file syntax and the exception class on a parse error differ (see
"Parse errors").

## The common model

The root of the config is an object (a map) with two required sections:

- `schema` — **the contract**: which models, attributes, relations, actions exist, which
  fields are available for writing/inclusion, list rules (pagination, sorting,
  filters). Describes "what", is not bound to a specific implementation of entities.
- `impl` — **the implementation**: PHP entity classes, how to read/write fields
  (getter/setter/add), constructor parameters, service dictionaries
  (locators/savers/removers/mutators/relationMutators), `userModel`.
- `constraints` — **constraints** (an optional section): declarative rules
  on values of attributes and relations (lengths, ranges, formats, being required depending on
  the create/update context). It is matched with schema by the same keys. This is
  **input data of the prebuild**, not of the working config: the prebuild checks it,
  produces from it a native check artifact for the target language (for PHP —
  Symfony Validator metadata) and outputs the working config already without it. At runtime
  the rules are applied by the `ConstraintCheckerInterface` adapter. Details —
  [constraints.md](constraints.md). The `types` section is there too (reusable
  named types: enums, semantic types on top of scalars).

Both sections must be present and be objects. Empty sections are allowed.

The loader performs the steps in a strict order:

1. Parses `schema` → internal Schema DTOs.
2. Parses `impl` → internal Impl DTOs.
3. Validates impl dictionaries, the absence of "orphaned" impl entries, the **completeness of
   impl relative to schema** and schema↔impl consistency **before** merging.
4. Merges schema and impl into a single `Config` (`Merger`).
5. Runs post-validations over the merged config (id, filters, services,
   mutators, userModel).

The minimal valid config — empty sections:

```json
{
  "schema": {},
  "impl": {}
}
```

```yaml
schema: {}
impl: {}
```

The minimal meaningful config — one model with an `id` attribute:

```json
{
  "schema": {
    "models": {
      "user": { "attributes": { "id": { "type": "int" } } }
    }
  },
  "impl": {
    "models": {
      "user": {
        "class": "App\\Entity\\User",
        "attributes": { "id": { "getter": "getId" } }
      }
    }
  }
}
```

```yaml
schema:
  models:
    user:
      attributes:
        id:
          type: int
impl:
  models:
    user:
      class: 'App\Entity\User'
      attributes:
        id:
          getter: getId
```

> Note the escaping of backslashes: in JSON the class is written as
> `App\\Entity\\User`, in YAML inside single quotes — as `App\Entity\User`.

## Keys as identifiers

Everywhere elements are named (models, attributes, relations), **the name is the key
of the object**, not a field inside:

- `schema.models` — a map `model_type → description`. The type is an arbitrary string
  (`user`, `order_item`, `project_dto`).
- `attributes` — a map `attribute_name → description`.
- `relations` — a map `relation_name → description`.
- `schema.actions` — a map `model_type → { action_type → description }`.

Lists (for example `["profile", "roles"]`) are exactly sequences. If
a list is passed instead of a map where a map is expected (or vice versa), the loader throws
an error like `... must be an object, not an array` / `... must be a list`.

Method/property names (getter, setter, add, mutatorSetter) are validated as
PHP identifiers: letters, digits, `_`, UTF-8 letters; they do not start with a digit.

## Where the parts of the config are described

- models, attributes, relations (`schema.models`, `impl.models`) — [models.md](models.md);
- actions, `writable`/`include`/`includable` (`schema.actions`, `impl.actions`) — [actions.md](actions.md);
- `access`, `userModel` — [access.md](access.md);
- the `config` of a list action — [list.md](list.md);
- the `locators`/`savers`/`removers` dictionaries — [persistence.md](persistence.md);
- mutators — [mutators.md](mutators.md);
- types — [types.md](types.md);
- the `constraints` and `types` sections — [constraints.md](constraints.md).

## The `impl` section

The structure of `impl`:

| Field              | Type             | Purpose                                     |
| ------------------ | ---------------- | ------------------------------------------- |
| `models`           | map              | Implementation of models (classes, getter/setter). |
| `actions`          | map              | Binding of actions to services.             |
| `locators`         | map alias→class  | Locator classes.                            |
| `savers`           | map alias→class  | Saver classes.                              |
| `removers`         | map alias→class  | Remover classes.                            |
| `mutators`         | map alias→class  | Attribute mutators.                         |
| `relationMutators` | map alias→class  | Relation mutators.                          |
| `userModel`        | string           | The user model type.                        |
| `defaultLocator`   | string           | Locator for models without their own (see [models.md](models.md), "Model locator"). |

## Generating a config for a task

The recommended order when writing a new config:

1. **Define the models.** For each one set `schema.models.<type>` and
   the corresponding `impl.models.<type>` with `class`. Each type needs an
   `id` attribute with a working getter in impl.
2. **Describe the attributes.** In schema — `type` (+ `writable` if writing
   is restricted). In impl — be sure to create an entry with the same name for **each**
   attribute, inside it specify getter/setter/constructorParam. If the class has
   public properties with the same names, `getter`/`setter` themselves can be omitted
   (auto-detection), but the attribute entry in impl cannot be skipped.
3. **Describe the relations.** In schema — `type` and `targetModel` (the target type must
   exist among the models). In impl — create an entry with the same name for each
   relation; specify getter/setter/add inside it if necessary.
4. **Describe the actions.** In `schema.actions.<model>` list the required
   `create/read/list/update/delete`. Add `access`, `writable`, `include`,
   `includable`, `config` as needed. In `impl.actions.<model>` bind
   `entityLocator/entitySaver/entityRemover` and (for list) `config.class` /
   `config.repository`.
5. **Declare the services.** Each used locator/saver/remover alias must
   be in the corresponding `impl` dictionary, and vice versa — there must be no extra (unused)
   ones.
6. **Add mutators** (if needed): the declaration by phase in schema + classes in
   impl.
7. **Specify `userModel`** (if the system has role-based authorization).
8. **Add constraints** (if needed): the `constraints` section — rules on
   attributes/relations, split into base and contextual (create/update);
   reusable value sets — the `types` section. Only rules closed
   over the schema (type + literals); rules with `this`/environment — attributes on
   classes. After editing — rerun the prebuild, the generated artifact is not edited
   by hand. See [constraints.md](constraints.md).

The pairing of schema↔impl keys is strict in **both** directions: any impl attribute/relation/
model/action must have a pair in schema (otherwise — an "orphaned impl"), and any
schema attribute/relation/model must have a pair in impl (otherwise — an "incomplete impl").
Actions in impl are optional: an action can be described only in schema (then
`entityLocator/entitySaver/entityRemover` stay `null`). Details and error texts
are in the sections "orphaned impl" and "Completeness of impl relative to schema".

## Config validation

Below is what exactly is checked and under which condition loading fails. It is convenient
to use as a checklist when reviewing a finished config. All errors are
`InvalidConfigStructureException` or `MissingRequiredFieldException`, unless stated
otherwise. Both belong to the `FrameConfigurationException` category (500).

### Structure and required fields

- The root is not an object / is a list / a scalar → `Root element must be an object`.
- No `schema` or `impl`, or they are not objects → `Field 'schema'/'impl' must be an object`.
- An attribute without `type` → the required field `type` is missing.
- A relation without `type` or `targetModel` → a required field is missing.
- An impl model without `class` → the required field `class` is missing.
- A map is given as a list (or vice versa) → `... must be an object, not an array` /
  `... must be a list`.
- An invalid action type → `Invalid action type '...'. Allowed values: create, read, list, update, delete`.
- `includable` is given as a list → `Field '...includable' must be an object or null`.
- a collection in `writable` of an update action with `setter: false` → `Collection relation '...' is writable for update on model '...' but has 'setter: false': update replaces the whole collection, declare a setter or remove the path from 'writable'`.
- a nested `writable` path without its parent (`"author.address": create` without `author`) → `Writable path 'author.address' of action '...' on model '...' requires its parent 'author' to be declared in 'writable' too`.

### schema ↔ impl consistency (before merge)

- **Orphaned impl**: an impl model/attribute/relation/action without a pair in schema →
  `impl.models[...]... has no corresponding ... in schema`.
- **Completeness of impl relative to schema** (`validateNoMissingImpl`): a schema model/
  attribute/relation without a pair in impl. Checked before merging, so an incomplete impl
  gives a clear validation error rather than an `Error` from `Merger`:
  - the model is in schema but not in impl →
    `schema.models[<type>] has no corresponding model in impl`;
  - the attribute is in the schema model but not in the impl model →
    `schema.models[<type>].attributes[<n>] has no corresponding attribute in impl`;
  - the relation is in the schema model but not in the impl model →
    `schema.models[<type>].relations[<n>] has no corresponding relation in impl`.
    Actions are not checked: they can be described only in schema.
- **writeMode ↔ impl** (`validateSchemaImplConsistency`):
  - `writable:false` (ReadOnly), but impl sets a setter → `... declares a setter but schema writeMode is 'read-only'`.
  - `writable:false`, but impl sets `constructorParam:true` → `... declares constructorParam=true but schema writeMode is 'read-only'`.
  - `writable:"create-only"`, but impl sets a setter → `... declares a setter but schema writeMode is 'create-only'`.
  - `writable:true` (Write), but impl sets `constructorParam:true` → `... declares constructorParam=true but schema writeMode is 'write' (use 'create-only' instead)`.

### The constraints section (if present)

Checked by the prebuild; the section does not get into the working config.

- A reference to a non-existent model / attribute / relation →
  `constraints: unknown model "..."` / `...: unknown attribute` / `...: unknown relation`.
- A rule is not applicable to the property type (the applicability matrix, including
  resolution of named types from `types` to their kind) →
  `...: rule "..." is not applicable to this type` (with the group indicated:
  `(rules)`, `(create)`, `(update)`, `(each)`).
- `each` on a non-list attribute → `"each" is only allowed on list types`;
  `each` on a relation → `"each" is not allowed on relations`.
- A required rule parameter is missing → `rule "..." requires param "..."`.

All errors are collected at once, not up to the first one.

### impl dictionaries

- locators/savers/removers/mutators/relationMutators are given as a list →
  `impl.<dict> must be an associative array, not a list`.
- An empty key → `impl.<dict> key must be a non-empty string`.
- An empty value → `impl.<dict>[...] value must be a non-empty string class name`.


### Post-validations (after merge)

- **Model ids**: a model without an `id` attribute with a non-empty string getter →
  `Model '<type>' must define an attribute 'id' with a getter`. For `id` the getter
  is required **as a string**: auto-detection by a public property does **not**
  apply to `id`. `getter: null` (absence) and `getter: false` for `id`
  are invalid — an explicit string method/property is needed (for example `"getter": "getId"`).
- **Boolean filters**: for a list filter on a field of type `bool`/`boolean` any
  operator except `equal` → `Boolean filter '...' in model '...' supports only 'Equal' operator, '...' given`.
  The field type is determined the same way as at runtime: if the filter has a `typeSpec` —
  by it (bool ⇔ `typeSpec` casts to `bool`), the model attribute is not taken into account;
  otherwise — by the type of the model attribute with the same name.
- **List action filters** (`validateListFilters`, only for actions with
  a non-empty `config.class`) — consistency of `config.filters` with the schema and
  the generated DTO classes:
  - the list action refers to an unknown model →
    `List action refers to unknown model '...'`;
  - a rule without operators →
    `Filter rule '...' of model '...' must declare at least one operator`;
  - the rule path does not resolve to a schema attribute (through a chain of relations) and
    the rule has no `typeSpec` →
    `Filter rule '...' of model '...' does not resolve to a schema attribute and has no typeSpec`;
  - rules are declared, but the Filter class is missing or the root class has no
    `filter` property →
    `Model '...' declares filter rules but filter class '...' is missing or root class '...' has no 'filter' property`;
  - a rule operator without a generated class →
    `Filter rule '...' of model '...' declares operator '...' but class '...' is missing`;
  - the Filter class has no property for the operator →
    `Filter class '...' has no property '...' for operator '...' declared by rule '...' of model '...'`;
  - a rule path without a property in the operator class (the config declares
    a non-existent capability) →
    `Filter rule '...' of model '...' has no matching property '...' in class '...'`;
  - a rule on a relation declares an operator other than `equal`/`in` →
    `Filter rule '...' of model '...' resolves to a relation and supports only 'equal', 'in' operators, '...' given`;
  - the target model of a relation rule has no locator (see [models.md](models.md),
    "Model locator") →
    `Filter rule '...' of model '...' resolves to relation target '...' which has no entity locator. Set impl.models[...].entityLocator, a read action with entityLocator or impl.defaultLocator`;
  - reverse drift — a public property of an operator class without a covering
    rule (after switching to the whitelist it is not filterable, dead code) →
    `Property '...' of class '...' has no corresponding rule in 'filters' of model '...' list action`.

  The path resolution and non-empty operator checks do not depend on PHP classes and
  always work; checks that require reflection over the generated classes
  are skipped while `config.class` does not exist (the class generator itself
  loads the config — a hard requirement would break the first generation). After
  generation the next config load validates fully. Class properties
  are read with PHP functions (`property_exists`, `get_object_vars` on a fresh
  instance), the Reflection API is not used.

- **Relation target models**: a relation refers to a non-existent `targetModel` →
  `Relation '...' in model '...' refers to unknown target model '...'`.
- **Locators/Savers/Removers**:
  - an empty alias → `Invalid 'entityLocator|entitySaver|entityRemover' for action '...' on model '...': must be a non-empty string`
    (if the config is assembled bypassing the loader, at runtime the same gives
    `ActionAliasNotConfiguredException` — a configuration error → 500);
  - the alias is not declared in the dictionary → `Action '...' on model '...' references undefined locator|saver|remover '...'`;
  - declared but not used → `Locator|Saver|Remover '...' is declared but not used by any action`.
- **Mutators / RelationMutators**:
  - the class does not exist → `... refers to undefined class '...'`;
  - the class does not implement the required interface → `... must implement AttributeMutatorInterface|RelationMutatorInterface`;
  - `mutatorSetter` without declared mutators → `... declares mutatorSetter but has no mutators`;
  - there are mutators but no valid setter/mutatorSetter → `... uses mutators but has no valid setter or mutatorSetter` (for a relation: `... has setter explicitly disabled and no mutatorSetter`);
  - there are mutators with `constructorParam:true` → `... uses mutators but is defined as constructorParam`;
  - a reference to an undeclared alias → `... references undefined mutator alias '...'`;
  - a declared but unused alias → `... is declared but not used by any ...`.
- **userModel**: the type is missing among the models → `UserModel '...' does not exist in the models list`; the class does not implement `UserInterface` → `User class '...' must implement UserInterface`.
- **repository** (from `impl.actions.<model>.list.config.repository`): the class does not
  exist → `Repository class '...' ... does not exist`; does not implement
  `EntityCollectionInterface` → `... must implement EntityCollectionInterface`.

> Some of the post-validations (mutator classes, userModel, repository) rely on
> `class_exists`/`class_implements`, that is, they require the corresponding
> PHP classes to be loadable at the moment the config is parsed.

## Parse errors

| Loader             | File read error                                      | Parse error                                          | Root is not an object                                                |
| ------------------ | ---------------------------------------------------- | ---------------------------------------------------- | -------------------------------------------------------------------- |
| `JsonConfigLoader` | `InvalidJsonException` (`Unable to read file '...'`) | `InvalidJsonException` (`Failed to parse JSON: ...`) | `InvalidConfigStructureException` (`Root element must be an object`) |
| `YamlConfigLoader` | `InvalidYamlException` (`Unable to read file '...'`) | `InvalidYamlException` (`Failed to parse YAML: ...`) | `InvalidConfigStructureException` (`Root element must be an object`) |

The parsing semantics (structure, validations, merge) after successful parsing is common.
`YamlConfigLoader` is shipped by the Symfony bundle
(`UniMethod\SymfonyLib\Config\InvalidYamlException`). All exceptions in the table are
of the `FrameConfigurationException` category.

> These are errors of the **config loading stage**: they occur where the application
> loads the config — at startup or, if the config is created lazily (as in
> the Symfony bundle), when loading routes and on the first request. In the latter case
> they reach the application boundary together with runtime errors, and the boundary
> distinguishes them by the marker. The framework divides runtime request errors into domain ones
> (4xx, shown to the client), configuration errors and other failures (both — 5xx, to
> the boundary). The error model, codes and the markers `FrameDomainException` /
> `FrameConfigurationException` are described in
> [errors.md](errors.md).

## YAML notes

- The root is a map (not a list). A list at the top level → `Root element must be an object`.
- `null` is written as `~` or an empty value; an empty map — `{}`, an empty
  list — `[]`.
- `getter: false` / `writable: false` are boolean values, which is what is needed
  (`false` = do not use / ReadOnly). For auto-detection the `getter`/
  `setter` field is omitted, but the attribute/relation entry itself is kept in impl (it cannot be
  omitted entirely — see "Completeness of impl relative to schema").
- Put class names with backslashes in **single** quotes
  (`'App\Entity\User'`), otherwise `\` may be interpreted as an escape.
- `create-only` is a regular string, quotes are not required.
- Relation modes (`reference`, `create`, `reference_or_create`) are also regular
  strings without quotes.
