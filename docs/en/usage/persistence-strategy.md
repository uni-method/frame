# Choosing a persistence strategy: what we use where

A document about how an entity/model gets into storage and how to choose the approach for a business task. It is about the `entityLocator` + `entitySaver` combination of a writing action and about whether a separate request model is needed instead of direct mapping onto an ORM entity.

## The big picture

Any writing action (`create`/`update`) is made up of two parts:

- **entityLocator** — how to find an existing object (for `update`) or prepare a new one. Most often the standard ORM locator, less often a custom one (for example for a DTO over several entities). The locator answers only "find by id"; "is this user allowed this object" is a constraint on the entity class, which the framework checks in the `AfterLocate` phase right after the locator ([validation-patterns.md](validation-patterns.md), pattern 7).
- **entitySaver** — what to do with the sent data. Either a declarative ORM saver (spread the fields over the entity and commit), or a custom saver with business logic.

The choice is determined by one question: **does "save" coincide with "spread the sent fields over the columns and commit"?** If yes — the standard ORM saver. If there is a transformation, a side effect or a rule between the input and the DB state — a custom saver, and almost always a separate request model along with it.

## Three strategies

### A. Direct ORM mapping (entity = model)

The standard locator + the standard saver. The model points directly to the ORM entity, getters/setters are the entity's methods.

When: CRUD without logic. The client sends fields → they go into a row → commit. The response is the same entity as is. Relations are passed as references.

Typical cases: reference books, simple domain entities that are edited "as is" without recalculations and side effects.

### B. A custom saver over an ORM entity

The standard locator + a custom saver, but the model may still be mapped onto the entity. The saver encapsulates a domain operation: recalculation, cascade, writing history, a status transition.

When: the data goes into existing entities, but through a non-trivial procedure.

Typical cases: transitions in a status state machine with writing to a log; saving a record that drags along a recalculation of related aggregates; bulk insertion of a set of rows from a single request.

### C. A request DTO + a custom saver (model ≠ entity)

A separate request model, not an ORM entity. The saver itself builds/finds entities, performs side effects and writes computed values back into the model for the response.

When: the request/response contract does not match the table structure — computed fields in the response, a mismatch in the set of fields, domain rules with controlled HTTP codes, several affected entities. Details — in [create-dto.md](create-dto.md).

Typical cases: authentication and issuing a token; changing/resetting a password; creating a user with password generation and expansion of fields; a computation request that returns a calculated result without saving the request itself.

## Comparison table

|                              | A. Direct ORM                     | B. Custom saver over an entity    | C. DTO + custom saver         |
| ---------------------------- | --------------------------------- | --------------------------------- | ----------------------------- |
| The model is mapped onto     | an ORM entity                     | an ORM entity                     | a separate POPO request model |
| entitySaver                  | standard                          | custom                            | custom                        |
| Input = table structure      | yes                               | yes (mostly)                      | no                            |
| Computed fields in the response | no                             | rarely                            | yes                           |
| Side effects                 | no                                | yes                               | yes                           |
| Domain rules → HTTP codes    | no (only DB constraints → 500)    | yes                               | yes                           |
| Several entities at once     | no                                | possible                          | often                         |
| Maintenance cost             | minimal                           | medium                            | high                          |

## How to choose

The default is strategy A; a custom saver is a last resort, it requires a serious argument. Almost everything that at first glance "requires a saver" is resolved by the declarative means of the framework:

- writing relations — `writable: {x: reference}` in the config + the declaration `?included=x` and the reference `{"x": {"id": …}}` in the body (the frontend prepares relations and sends references; several entities = several requests, not one saver over all of them);
- creating a related entity together with the root one — `writable: {x: create}`, and if the relation must accept both a reference by `id` and a new object — `reference_or_create` (plus `cascade: ['persist']` and `Assert\Valid` on the entity property);
- input validation — the `constraints` section of the config (rules by type: being required, lengths, formats, dictionaries) plus `Assert\*` attributes on the entity for rules with `this`/environment; the framework runs both before saving and returns 422 without a single line of code in the saver;
- access to an object by owner/role on read/update/delete — a constraint with action groups (the `AfterLocate` phase), not a custom locator;
- hiding/transforming fields — mutators (`hide`, `hasher`, etc.);
- computed relations in the response — a Criteria getter or a relationMutator (see [list-action.md](list-action.md)).

Only if the task is not covered by this set, go from top to bottom — the first item that matches gives the answer:

1. Does the response contain a value that was not in the input and that does not lie in the entity one-to-one (a generated secret, a token, a computed result)? → **C**.
2. Does the set of input fields structurally diverge from the entity (one field expands into an array; a "virtual" entity over several tables)? → **C**.
3. Is there a domain rule that must give a meaningful code (422 on a uniqueness violation instead of 500; 403 on lack of rights)? → if the rule is about the request contract — **C** (the model validator + checks in the saver); if it is about a procedure over existing entities — **B**.
4. Does saving drag along a side effect — writing history, recalculating related rows, a cascade, sending a notification, a bulk insertion? → **B**.
5. None of the above, "accept the fields and save"? → **A**.

## Anti-patterns

- **A saver where declarative means are enough.** Checking a field format — an Assert attribute on the entity; a secret in the response — the `hide` mutator; writing a relation — reference + `?included`; a computed relation — a relationMutator. A saver for the sake of any of these items is an extra layer of code, tests and configuration.
- **A saver for the sake of "id or a new object".** The choice between a reference and creation is made by the `reference_or_create` mode: the branch is determined by the shape of the payload, custom code is not needed for this.
- **A saver for the sake of a unique constraint — depending on circumstances, not by reflex.** A duplicate on a unique index without a check in the saver will fly to a 500 instead of a 422. A deliberate compromise is to leave uniqueness to the DB index if the UI rules out duplicates (precedents: `resourceCountry` hides already bound countries in the select, `whitelabelCredential` — the cabinet+whitelabel pair). A check in the saver is justified when a 422 is needed as part of the contract (a taken email when creating a user).
- **Domain checks in DB constraints instead of a saver.** A constraint protects integrity, but does not give a normal domain response. A business rule lives in the saver/validator.
- **A custom locator for the sake of an access check.** "Give the object to the author and the admin, an error for everyone else" is not a lookup-by-id task. For this there is the `AfterLocate` phase: a constraint on the class with `groups: ['read', 'update', 'delete']`, the standard ORM locator stays. A locator returning `null` for someone else's object is only a deliberate choice of "404 instead of 403", and even then a temporary one (the option is planned in the framework).
- **A new model for a single CRUD entity without logic.** If creation is trivial, a separate DTO is extra code and extra mapping. Use A.
- **Technical exceptions as client errors.** An exception about a saver↔model mismatch is not a domain one and means a configuration error → 500: it implements `FrameConfigurationException`, not `FrameDomainException`. For client input errors — domain validation/access exceptions with correct codes.
