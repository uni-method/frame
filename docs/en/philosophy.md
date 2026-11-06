# Philosophy

The framework turns an HTTP request to a model into a call of application code and back.
The config describes **what** (models, attributes, relations, actions); the application code —
**how**: which classes and interfaces you implement for this config. The rest —
request parsing, mapping, structure checks, mutators, building the response — is done by
the framework.

## Principles

- **The config is a contract.** `schema` describes what exists and what is allowed; `impl` —
  what it is implemented with. Everything the client can send or request is declared
  in the config explicitly: `writable`, `includable`, `config.filters`, `config.sort` are
  whitelists. See [domains/config.md](domains/config.md),
  [domains/actions.md](domains/actions.md), [domains/list.md](domains/list.md).
- **Strictness instead of silent skips.** An undeclared field, operator, relation is
  a client error (400/422), not silently dropped data. A mismatch between the config and
  the code is caught at startup by bootstrap validation, not on the first request.
- **Input declaration.** On create/update `?included=` is an obligation: the body
  must match it. See [domains/actions.md](domains/actions.md).
- **Fail-closed.** Without a user provider a non-trivial `access` is forbidden.
  See [domains/access.md](domains/access.md).
- **Each check has its own place.** A rule lives in a constraint on the object,
  a saver does only side effects, a locator does only lookup by id. See
  [domains/constraints.md](domains/constraints.md),
  [usage/validation-patterns.md](usage/validation-patterns.md),
  [usage/persistence-strategy.md](usage/persistence-strategy.md).
- **Errors by category.** Domain errors are determined by client input and give
  4xx; configuration errors and failures go to the application boundary and give 500. See
  [domains/errors.md](domains/errors.md).
- **No imposed infrastructure.** An entity is a plain PHP class, no ORM is
  imposed; the framework accesses it via getters/setters or public
  properties. See [domains/models.md](domains/models.md).
- **No request state.** Services can be kept as singletons in a
  long-lived worker. See [usage/getting-started.md](usage/getting-started.md).

## What you write and what the framework does itself

The framework takes care of request parsing, mapping data into your objects and back,
validating the structure against the config, applying mutators by phase and building
the response. You are required to provide the "edges" of this pipeline:

- **Entity classes** — your domain objects (what is `impl.models[*].class` in the config).
- **Persistence implementations** — how to find, save, delete and list entities
  (`EntityLocatorInterface`, `EntitySaverInterface`, `EntityRemoverInterface`,
  `EntityCollectionInterface`).
- **Constraint checking** — an adapter to your validation library
  (`ConstraintCheckerInterface`); the framework calls it in fixed phases
  with a context (see [domains/constraints.md](domains/constraints.md)).
- **User provider** — who the current user is and what they are allowed to do
  (`UserProviderInterface`), if you use access control.
- **Mutators** (optional) — value transformations by phase
  (`AttributeMutatorInterface`, `RelationMutatorInterface`).
- **Application boundary** — the entry point that calls `ApiService::execute`,
  catches unhandled exceptions (separately — configuration errors) and produces a 500
  (see [domains/errors.md](domains/errors.md)).

Some of the classes for list actions (`{Model}Filter`, `{Model}Sort`,
`{Model}Pagination` and operator classes) are **generated** from the config — you do not
write them by hand, but you need to know the naming conventions to understand errors and
write validation (see [domains/list.md](domains/list.md)).

## Structure of responsibilities
```
JsonConfigLoader
├── JSON structure check
├── Check that the id getter exists
├── Check validity of locators
└── Creating Config

ActionValidator
├── Checks action / model / relation / writable / include
├── Checks whether modes are allowed (reference/update/create)
└── Does not touch specific ids, does not look up entities

MappingToObject
├── Builds objects according to Config
├── Determines id and fields
├── Checks the existence of entities by id (via locators)
└── Applies type casting and setters
```
