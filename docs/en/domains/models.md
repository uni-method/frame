# Models and entities

A model is described in the config twice: in `schema` — the contract (attributes, relations), in `impl` — the implementation (class, getter/setter). The implementation is a plain PHP class — an entity.

## Model (schema part)

```json
{
  "schema": {
    "models": {
      "user": {
        "comment": "System user",
        "attributes": { "id": { "type": "int" } },
        "relations": {}
      }
    }
  }
}
```

```yaml
schema:
  models:
    user:
      comment: System user
      attributes:
        id:
          type: int
      relations: {}
```

Model fields: `comment?`, `attributes` (a map), `relations` (a map). All are
optional, except that a model should practically always have an `id`
attribute (see [config.md](config.md), "Config validation"). Each model described in schema must have
a paired entry in `impl.models` — otherwise loading fails even before merging (see
"Completeness of impl relative to schema").

## Attribute (schema part)

| Field      | Type                               | Purpose                                                                                                                                       |
| ---------- | ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `type`     | string (**required**)              | Value type (see [types.md](types.md)).                                                                                                       |
| `writable` | `true` / `false` / `"create-only"` | Write semantics.                                                                                                                              |
| `mutators` | phases object                      | Mutators by phase.                                                                                                                            |
| `comment`  | string                             | Comment. Set **only** in schema — the impl part carries no comment field, after merging `Attribute.comment` is always taken from schema.     |

`writable` controls how the field behaves on writing (a
`SchemaWriteMode` value):

- `true` → **Write**: the field is writable via the setter.
- `false` → **ReadOnly**: setter and `constructorParam` are forcibly disabled.
- `"create-only"` → **CreateOnly**: the setter is disabled, the field is set only
  via the constructor.

If `writable` is not specified, the schema does not restrict writing, and the behavior is entirely
determined by the impl part.

```json
{
  "schema": {
    "models": {
      "article": {
        "attributes": {
          "id": { "type": "int" },
          "title": { "type": "string", "writable": true },
          "slug": { "type": "string", "writable": "create-only" },
          "createdAt": { "type": "datetime", "writable": false }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    article:
      attributes:
        id:
          type: int
        title:
          type: string
          writable: true
        slug:
          type: string
          writable: create-only
        createdAt:
          type: datetime
          writable: false
```

## Relation (schema part)

| Field         | Type                     | Purpose                                                        |
| ------------- | ------------------------ | -------------------------------------------------------------- |
| `type`        | string (**required**)    | `oneToOne`, `oneToMany`, `manyToOne`, `manyToMany`.            |
| `targetModel` | string (**required**)    | Type of the target model.                                      |
| `mutators`    | phases object            | Mutators by phase.                                             |
| `comment`     | string                   | Comment. Set **only** in schema — impl does not carry it.      |

```json
{
  "schema": {
    "models": {
      "order": {
        "attributes": { "id": { "type": "int" } },
        "relations": {
          "customer": { "type": "manyToOne", "targetModel": "customer" },
          "items": { "type": "oneToMany", "targetModel": "order_item" }
        }
      }
    }
  }
}
```

```yaml
schema:
  models:
    order:
      attributes:
        id:
          type: int
      relations:
        customer:
          type: manyToOne
          targetModel: customer
        items:
          type: oneToMany
          targetModel: order_item
```

## Model (impl part)

`class` is required. `attributes`/`relations` are maps, the keys must match
the names from schema. The pairing is strict in both directions: each attribute and each relation
from schema must have an entry with the same name in impl (absence is a loading error,
see [config.md](config.md), "Completeness of impl relative to schema"), and vice versa — there must be no extra impl
entries. Getter/setter auto-detection works **inside** a present
impl entry (when the `getter`/`setter` fields are omitted), but the entry itself cannot be
omitted.

Attribute fields (impl):

| Field              | Values                               | Meaning                                                                                                |
| ------------------ | ------------------------------------ | ------------------------------------------------------------------------------------------------------ |
| `getter`           | string / `false` / absent (null)     | Method/property for reading. `false` — do not use; absence — auto-detection by public property.        |
| `setter`           | string / `false` / absent (null)     | Method/property for writing.                                                                           |
| `mutatorSetter`    | string / `false` / absent (null)     | Method/property for writing by a mutator.                                                              |
| `constructorParam` | bool                                 | Passed via the constructor.                                                                            |

Relation fields (impl) are the same plus `add` (the method for adding an element for `*ToMany`).

The semantics of `false` / `null` (absence) is the same for all of getter/setter/add/mutatorSetter:

- **a string** — an explicit name of a method or a public property;
- **`false`** — do not use the field/method;
- **absent / `null`** — auto-detection by a public property with the same
  name.

The exception is the `id` attribute: for it the getter must be a non-empty string,
auto-detection and `false` are not allowed (see [config.md](config.md), "Post-validations").

### Model locator

What the model is loaded by id with when it is referenced: a reference in `writable`
(`reference`, `reference_or_create`) and a list filter by a whole relation. The order
of choice:

1. `impl.models.<model>.entityLocator`;
2. `entityLocator` of the model's read action;
3. `impl.defaultLocator` — a common locator for models without their own.

```yaml
impl:
  defaultLocator: doctrine
  models:
    country:
      class: 'App\Entity\Country'
      entityLocator: doctrine    # may be omitted when defaultLocator is set
  locators:
    doctrine: 'App\Persistence\DoctrineLocator'
```

A read action is not needed for this: it only opens `GET /<model>/{id}`.
Update/delete locators are not used, one model's locator is not substituted for
another. The alias must be in `impl.locators`, otherwise the loading error
`Field 'impl.models[...].entityLocator' references undefined locator '...'`
(for `defaultLocator` — `Field 'impl.defaultLocator' …`). If there is no locator in
any way, a reference to the model is the configuration error `DtoReferencedException`
(500), a filter by relation to it is a loading error.

`constructorParam` is cast to bool softly: `true`, the string `"true"`, `1`,
`"1"`, `"yes"` → `true`; everything else → `false`.

> **Writing `*ToMany`: the setter takes precedence over `add`; on update the setter
> is required** (see [actions.md](actions.md), "Collections on update: PATCH"). When writing a collection
> the mapper first resolves the setter; if the setter is defined (including
> **auto-detection by a public property** with the same name), the mapper
> assigns the **whole array at once via the setter** to the relation, and `add` is not
> called. `add` triggers only when the setter is explicitly disabled
> (`setter: false`) or does not resolve. A common trap: the collection is declared
> as a public property and only `add` is set — the auto-detected setter overrides
> `add`, and the mapper tries to assign a PHP array to the public property. If
> the property is typed as a collection object (for example
> `Doctrine\Common\Collections\Collection`), assigning an array will fail with
> `Cannot assign array to property ... of type Collection`. Solutions: either
> declare the property as `array`, or set a `setter` that accepts an array and
> fills the collection inside (via its own `add` calls).

> **Reading `*ToMany`: the getter must return an `array`.** When serializing
> the response the mapper requires the getter of a `*ToMany` relation to return exactly a PHP array
> of objects; anything else (including a collection object) leads to a type
> mismatch error. If the collection is stored inside the entity as a
> `Collection`, set the getter to an explicit method returning an array (for example
> `return $this->items->toArray();`), rather than relying on auto-detection by
> property. Single relations (`oneToOne`/`manyToOne`) do not require this — for them
> an object or `null` is expected.

```json
{
  "impl": {
    "models": {
      "project": {
        "class": "App\\Entity\\Project",
        "attributes": {
          "id": { "getter": "getId" },
          "name": { "constructorParam": true },
          "server": { "setter": "setServer" },
          "secret": { "getter": false }
        },
        "relations": {
          "items": { "setter": "setItems", "add": "addItem" }
        }
      }
    }
  }
}
```

```yaml
impl:
  models:
    project:
      class: 'App\Entity\Project'
      attributes:
        id:
          getter: getId
        name:
          constructorParam: true
        server:
          setter: setServer
        secret:
          getter: false
      relations:
        items:
          setter: setItems
          add: addItem
```

> In YAML `getter: false` is the boolean `false` (= do not use). To set
> auto-detection, the field is simply omitted (or `~` is written).

## Entity classes

An entity is a plain PHP class referenced by `impl.models[<type>].class`.
The framework does not require inheritance from its base classes and does not impose an ORM:
all that matters is that values can be read and written as described in `impl`.

### Reading and writing fields

For each attribute/relation `impl` sets `getter`/`setter` (and `add` for collections).
The value of each of them can be:

- **a string** — the name of a method or a public property;
- **`false`** — the operation is disabled (the field is not read/written);
- **omitted / `null`** — auto-detection by a public property with the same name.

Resolution is the same for all paths (input, output, mutators): first a method with
that name is looked up, then a public property. Therefore both options below work.

Via methods:

```php
final class User
{
    private int $id;
    private string $email;

    public function getId(): int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): void { $this->email = $email; }
}
```

Via public properties (getter/setter in `impl` can be omitted — auto-detection
will work):

```php
final class User
{
    public int $id;
    public string $email;
}
```

### The `id` requirement

Each model practically always has an `id` attribute, and for it the `getter`
must be a **non-empty string** — auto-detection and `false` are not allowed for `id`
(this is a config validation requirement, not a runtime one). At the entity level this
means: provide an explicit way to read the identifier (a `getId()` method or
a public `id` property specified as a string in `impl`).

### The constructor and `constructorParam`

If an attribute/relation is marked in `impl` as `constructorParam`, the framework passes
their values to the constructor **by name** (named arguments), not via the setter.
The remaining fields are set after the object is created via setter/property.

```php
final class Order
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,
    ) {}

    private string $status = 'new';
    public function setStatus(string $s): void { $this->status = $s; }
}
```

The constructor parameter names must match the names of attributes/relations from
the config. If the constructor throws an error (for example, an incompatible type), the framework
wraps it in `EntityInstantiationException` — a configuration error (see
[errors.md](errors.md)): this is a 500, a sign of a mismatch between the entity and the config.

### Collections (`*ToMany`)

For `oneToMany`/`manyToMany` relations writing is possible via `setter` (accepts
the whole array) or via `add` (adds one at a time). `setter` takes precedence:
if it is set (including by auto-detection by a public property), `add` is not
used. If `setter` is disabled (`false`) and `add` is set, the framework calls
the `add` method for each element.

```php
final class User
{
    /** @var list<Post> */
    private array $posts = [];

    /** @return list<Post> */
    public function getPosts(): array { return $this->posts; }
    public function addPost(Post $p): void { $this->posts[] = $p; }
}
```

On reading, the getter of a `*ToMany` relation may return any `iterable` — an array or
a `Traversable`. A Doctrine collection works as is, there is no need to wrap it in
`getPostsAsArray(): array`:

```php
final class User
{
    /** @var Collection<int, Post> */
    private Collection $posts;

    public function __construct()
    {
        $this->posts = new ArrayCollection();
    }

    /** @return Collection<int, Post> */
    public function getPosts(): Collection { return $this->posts; }
    public function addPost(Post $p): void { $this->posts->add($p); }
}
```

A scalar or a non-traversable object from such a getter is a mismatch between the entity and
the config: the framework will respond with a type mismatch error. This does not
apply to writing: `setter` still receives an array.
