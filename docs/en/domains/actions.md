# Actions

## Actions (schema part)

`schema.actions` is a map `model_type → { action_type → description }`. The action type is
one of: `create`, `read`, `list`, `update`, `delete`. Any other value is
the error `Invalid action type '...'. Allowed values: create, read, list, update, delete`.

Fields of an action description (all optional):

| Field        | Applicability             | Purpose                                  |
| ------------ | ------------------------- | ---------------------------------------- |
| `access`     | any                       | Authorization/role restrictions.         |
| `writable`   | create/update             | Input: relations the client can declare in `?included=` and pass in the body. |
| `include`    | any                       | Output: relations always present in the response. |
| `includable` | any                       | Output: what the client can add to the response via `?included=`. |
| `config`     | list only                 | Pagination/sorting/filters.              |
| `comment`    | any                       | Comment.                                 |

```json
{
  "schema": {
    "models": { "user": { "attributes": { "id": { "type": "int" } } } },
    "actions": {
      "user": {
        "create": {},
        "read": {},
        "update": {},
        "delete": { "comment": "Deleting a user" }
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
  actions:
    user:
      create: {}
      read: {}
      update: {}
      delete:
        comment: Deleting a user
```

## writable, include, includable and the `included` parameter

This is the only place where the relations contract is described in full; other documents
refer here. Three config fields and one query parameter, each with exactly one
role:

| What         | Where           | Role                                                                 |
| ------------ | --------------- | -------------------------------------------------------------------- |
| `writable`   | action config   | **Input.** Which relations the client *is allowed* to pass and in which mode. |
| `include`    | action config   | **Output.** Relations that are always in the response.               |
| `includable` | action config   | **Output.** What the client can *add* to the response via `?included=`. |
| `?included=` | request query   | On create/update — the **input declaration**; on any action — a request for additional output. |

The parameter is called `included` (not `include`) and accepts a comma-separated string
or an array: `?included=author,items.product` ≡ `?included[]=author&included[]=items.product`.
Any other value — `400 Parameter 'included' must be a string or a list of strings.`

### Writing: the contract, then the body

On create/update the client first **declares** in `?included=` which relations it
passes, then sends the body. The framework checks three things, all before
mapping and before accessing the DB:

1. **Declaration ⊂ `writable`.** A path from `?included=` that is not in `writable`
   (exact match, see below) →
   `422 Relation '<path>' for '<model>' model is not writable for action '<action>'.`
2. **Body ⊂ declaration.** A relation is present in the body (with any value, including
   `null` and `[]`) but is not declared →
   `422 Relation '<path>' of model '<model>' is present in the request body but not declared in 'included'.`
3. **Declaration ⊂ body.** A path is declared but is not in the body →
   `422 Relation '<path>' of model '<model>' is declared in 'included' but missing in the request body.`
   A declaration is an obligation, not a permission. Declaring `author.address`
   and sending `author: null` is also an error: a nested declaration under `null`
   cannot be fulfilled.

Exactly **declaration ∩ `writable`** is written, the mode is taken from `writable`.
This does not concern attributes: they are passed in the body without a declaration.

```
writable: { author: create, "author.address": create, tags: reference }

POST /post?included=author,author.address
{ "title": "T", "author": { "name": "A", "address": { "city": "X" } } }
→ 201, the author with an address is created

POST /post
{ "title": "T", "author": { "name": "A" } }
→ 422 … 'author' … not declared in 'included'

POST /post?included=author,tags
{ "title": "T", "author": { "name": "A" } }
→ 422 … 'tags' … declared in 'included' but missing in the request body

PATCH /post/1?included=author
{ "author": null }
→ 200, the relation is unlinked (null is a valid value under a declaration)
```

### Explicit paths only

A `writable` key is a relation path in dot notation. The mode is **not inherited**:
`writable: { author: create }` allows only `author`; to write
`author.address`, a separate entry `"author.address": create` is needed. In addition,
the parent of a nested path must be declared itself —
`writable: { "author.address": create }` without `author` will not load:
`Writable path 'author.address' … requires its parent 'author' to be declared in 'writable' too`.
The same rule applies to the declaration in `?included=`.

### Modes

`writable` accepts path → mode (`reference`, `create`, `reference_or_create`)
or path → `{ mode, access? }`. The short list form — all paths get
`reference`. `access` inside, if not set, is inherited from the action's `access`;
it is checked only if the relation is present in the body (including `null`), a denial →
`403 Access denied for relation '<path>'.`

The branch is chosen by the shape of the payload, not by the mode: an object **with exactly
one `id` property** is a reference (`{ "id": null }` is not a reference: `422 Reference
'<path>' requires a non-null 'id' field.`; a missing entity → `404`);
any other object, including `id` alongside other fields, is creation.
`reference` accepts only a reference, `create` — only creation,
`reference_or_create` — both. A mismatch →
`422 Relation '<rel>' in model '<model>' does not allow mode '<mode>' for action '<action>'.`

A reference is loaded by the target model's locator — its own, the read action's, or
`impl.defaultLocator` (see [models.md](models.md), "Model locator"). Without
a locator — `DtoReferencedException` (500).

Creating a nested entity is performed by the same saver as the root one: for
the doctrine saver, `cascade: ['persist']` and `Assert\Valid` on the property are needed,
otherwise a 500 from the DB instead of a 422.

```json
{
  "schema": {
    "actions": {
      "post": {
        "create": {
          "writable": {
            "tags": "reference",
            "author": { "mode": "create", "access": { "authRequired": true } },
            "author.address": "create"
          }
        }
      }
    }
  }
}
```

```yaml
schema:
  actions:
    post:
      create:
        writable:
          tags: reference
          author:
            mode: create
            access:
              authRequired: true
          author.address: create
```

### Collections on update: PATCH

For `*ToMany` relations on update the JSON merge-patch semantics applies:

| In the body                 | Result                                      |
| --------------------------- | ------------------------------------------- |
| no key                      | the collection is untouched                 |
| `"tags": [{id:1},{id:2}]`   | the collection is replaced entirely with `[1, 2]` |
| `"tags": []` or `"tags": null` | the collection is empty                  |

There is no appending without removal: the replacement is done via the setter, so for a relation in
`writable` of an update action the setter must resolve. `setter: false` with only
`add` is a bootstrap error (`Collection relation 'tags' is writable for update …
but has 'setter: false'`); if the setter does not resolve at runtime (auto-detection
by property did not find a public field) —
`CollectionReplaceRequiresSetterException` (500). On create `add` is still
allowed: the collection is filled from scratch. For single relations `null` means unlink.

### Output: `include` and `includable`

- `include` — a list of paths that are **always** expanded in the response, without
  a query parameter. It is not filtered by `includable` and `access`. This is
  the way to return the result of the saver's work: a relation that the server fills itself
  is described **only** in `include`; there is no need to, and you must not, add it to `writable` —
  `writable` describes the input.
- `includable` — a map `path → access`: what the client can **add** to the output
  via `?included=`. The value `null` — no additional restrictions
  (the action's own `access` applies); `{ authRequired, roles }` — a separate
  restriction. Paths are compared exactly: `includable: { posts: null }` does not
  cover `posts.tags`. If there is no `includable` property, the client can
  request any relation of the model.
- A path from `?included=` that is not in `includable`, or to which there is no
  access, is **silently dropped** from the output (neither 400 nor 403). On
  create/update this does not cancel the input checks: the same path still must
  be in `writable` and in the body.

The response composition: `include` ∪ (`?included=` ∩ `includable`). The output on writing is
an ordinary read of the saved object, no special rules.

```json
{
  "schema": {
    "actions": {
      "user": {
        "read": {
          "include": ["profile", "posts.tags"],
          "includable": {
            "profile": null,
            "adminLogs": { "authRequired": true, "roles": ["admin"] }
          }
        }
      }
    }
  }
}
```

```yaml
schema:
  actions:
    user:
      read:
        include: [profile, posts.tags]
        includable:
          profile: ~
          adminLogs:
            authRequired: true
            roles: [admin]
```

> In YAML `null` is written as `~` or an empty value.

### Summary

| Request                                     | `writable`      | `include`   | Outcome                                           |
| ------------------------------------------- | --------------- | ----------- | ------------------------------------------------- |
| `POST ?included=user` + `{user:{id:1}}`     | `user: reference` | —         | 201, `user` in the response                       |
| `POST` + `{user:{id:1}}` (no declaration)   | `user: reference` | —         | 422 not declared                                  |
| `POST ?included=user` + `{}`                | `user: reference` | —         | 422 declared but missing                          |
| `POST ?included=token` + `{}`               | —               | `[token]`   | 422 not writable (`token` is not input)           |
| `POST` + `{}`                               | —               | `[token]`   | 201, `token` in the response (filled by the saver) |
| `GET ?included=adminLogs` without the admin role | —          | —           | 200 without `adminLogs`                           |

## Actions (impl part)

`impl.actions` is a map `model → { action → { entityLocator?, entitySaver?, entityRemover?, config? } }`.
`config` here carries only the PHP specifics of list: `class` (the root ListRequest DTO
aggregating filter/sort/pagination) and `repository` (the repository class
implementing `EntityCollectionInterface`). Pagination/sorting/filter parameters
live in `schema`. The impl list config accepts no other keys: any extraneous
key — including `filters` mistakenly put into the impl section — is a loading
error (`Unknown key '...' ... Allowed keys: class, repository`), not a silently
ignored entry, as it used to be.

These classes — `class` (the root `{Model}ListRequest`), the accompanying
`{Origin}Filter`/`{Origin}Sort`/`{Origin}Pagination`, operator classes and the
`repository` itself — you **write by hand**. The framework finds and fills them by strict
naming conventions (the name is derived from the model's entity class, the namespace — from
the root `config.class`), so naming is critical. For the full naming rules,
the encoding of relation paths and a repository example see
[list.md](list.md). Here, in the config, you only specify
the fully qualified name of the root class and the repository class.

`entityLocator`/`entitySaver`/`entityRemover` are **aliases** from the dictionaries
`locators`/`savers`/`removers`. By meaning: locator — for read/delete, saver —
for create/update, remover — for delete.

```json
{
  "schema": {
    "models": { "user": { "attributes": { "id": { "type": "int" } } } },
    "actions": { "user": { "read": {}, "create": {}, "delete": {} } }
  },
  "impl": {
    "models": {
      "user": {
        "class": "App\\Entity\\User",
        "attributes": { "id": { "getter": "getId" } }
      }
    },
    "actions": {
      "user": {
        "read": { "entityLocator": "doctrine" },
        "create": { "entitySaver": "doctrine" },
        "delete": { "entityLocator": "doctrine", "entityRemover": "doctrine" }
      }
    },
    "locators": { "doctrine": "App\\Locator\\DoctrineLocator" },
    "savers": { "doctrine": "App\\Saver\\DoctrineSaver" },
    "removers": { "doctrine": "App\\Remover\\DoctrineRemover" }
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
  actions:
    user:
      read: {}
      create: {}
      delete: {}
impl:
  models:
    user:
      class: 'App\Entity\User'
      attributes:
        id:
          getter: getId
  actions:
    user:
      read:
        entityLocator: doctrine
      create:
        entitySaver: doctrine
      delete:
        entityLocator: doctrine
        entityRemover: doctrine
  locators:
    doctrine: 'App\Locator\DoctrineLocator'
  savers:
    doctrine: 'App\Saver\DoctrineSaver'
  removers:
    doctrine: 'App\Remover\DoctrineRemover'
```
