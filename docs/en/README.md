# UniMethod\Frame — documentation

- [Philosophy](philosophy.md) — what the framework does, what you write, principles.
- [Development](development.md) — for contributors: environment, checks, test layout.

## Domains

A reference on the parts of the framework: the config and the application code that corresponds to it.

- [Config](domains/config.md) — loaders, the common model, validation, parse errors, YAML.
- [Models and entities](domains/models.md) — models, attributes, relations; entity classes.
- [Actions](domains/actions.md) — `schema.actions`/`impl.actions`, `writable`/`include`/`includable`, `?included=`.
- [Access](domains/access.md) — `access`, `userModel`, the user provider.
- [list requests](domains/list.md) — the `config` of a list action, generated DTOs.
- [Persistence](domains/persistence.md) — locators, savers, removers, repositories.
- [Mutators](domains/mutators.md) — value transformations by phase.
- [Types](domains/types.md) — attribute types and how they are written.
- [Constraints](domains/constraints.md) — the `constraints` section, prebuild, runtime check.
- [Errors](domains/errors.md) — exception categories, response codes, the application boundary.

## Usage

- [Getting started](usage/getting-started.md) — assembling `ApiService`, request format.
- [Adding a list action](usage/list-action.md)
- [Adding a create DTO](usage/create-dto.md)
- [Choosing a persistence strategy](usage/persistence-strategy.md)
- [Constraint patterns](usage/validation-patterns.md)
