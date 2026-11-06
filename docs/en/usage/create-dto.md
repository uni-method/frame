# Adding a create DTO

## When it is justified

The choice between a separate create DTO and direct mapping onto the entity is discussed in [persistence-strategy.md](persistence-strategy.md) (strategy C versus A/B). In short: a DTO is needed when the request/response contract does not match the table structure — computed fields in the response, a mismatch in the set of fields, domain rules with controlled HTTP codes, side effects. If creation comes down to "accept the fields and save" — a DTO is not needed, use the entity directly.

## Steps

### 1. The request model

`src/Api/Model/CreateSomething.php` is a plain POPO with public nullable properties and `getId()`. Symfony validation attributes are put right here; custom constraints (for example an access check based on the current user) too. The check runs in the `AfterInput` phase with the groups `['Default', 'create']` — a create DTO has no `AfterLocate` phase, the object does not exist in the DB. Type-based rules (being required, format) for the DTO can also be described in the `constraints` section of the config — the `createSomething` model there is a model just like an entity.

```php
final class CreateSomething
{
    #[Assert\NotNull]
    #[Assert\Email]
    public string|null $email = null;

    #[Assert\NotNull]
    #[CustomAccessConstraint]
    public string|null $role = null;

    public string|null $password = null;   // filled by the server, returned to the client

    public Entity|null $result = null;      // relation, if include is needed

    public function getId(): string
    {
        return md5((string) $this->email);
    }
}
```

Rules by field type:

- A field that the client **sends** but must not see in the response (the current password, etc.) — `mutators.beforeOutput: [hide]` in the schema.
- A field that the server **fills in order to show** (a generated password) — without `hide`, that is what it exists for.

### 2. The saver

`src/Api/Savers/CreateSomething.php` implements `EntitySaverInterface`. The structure of `save()`:

1. Input type check → `UnexpectedSaverInputException` (this is a configuration error, not a client one: it implements `FrameConfigurationException`, flies to a 500).
2. Checks of required fields and domain rules → `ValidationException` (422) or `AccessDeniedException` (403).
3. Integrity checks that would otherwise fail at the DB level (a taken unique key) → `ValidationException` **before** `flush()`.
4. Building and saving the entity.
5. Writing computed values back into the model (`$model->password = ...`, `$model->result = ...`) and `return $model`.

```php
public function save(object $objectToSave): CreateSomethingModel
{
    if (!$objectToSave instanceof CreateSomethingModel) {
        throw new UnexpectedSaverInputException("Only CreateSomething can be handled");
    }

    if (!$objectToSave->email) {
        throw new ValidationException("Email is required");
    }

    if ($this->em->getRepository(Entity::class)->findOneBy(["email" => $objectToSave->email])) {
        throw new ValidationException("Entity with this email already exists");
    }

    // ... building the entity, side effects ...
    $this->em->persist($entity);
    $this->em->flush();

    $objectToSave->password = $generatedPassword;
    $objectToSave->result = $entity;

    return $objectToSave;
}
```

The order of exceptions matters: input type → being required → rights → integrity → writing. Always check whether a unique field is taken before `flush()`, otherwise you get a 500 instead of a 422.

### 3. Exceptions

Use the existing ones from `src/Api/Exception/`, do not create new ones without need:

- `ValidationException` → 422, a general domain error with an arbitrary text.
- `AccessDeniedException` → 403, an authorized/entitled user is required.
- `InvalidCredentialsException` → 401.
- `UnexpectedSaverInputException` → **not** a domain one: it implements `FrameConfigurationException`, not `FrameDomainException`, flies to a 500 and is logged at the boundary as a configuration error. Only for a saver↔model mismatch.

### 4. Description in the API config

In the config ([domains/config.md](../domains/config.md)) four places are extended.

`schema.models` — the model contract:

```yaml
createSomething:
  attributes:
    id: { type: "string|null", writable: false }
    email: { type: "string|null" }
    role: { type: "string|null" }
    password: { type: "string|null" }
  relations:
    result:
      type: oneToOne
      targetModel: something
```

`schema.actions` — the action, access, include. The roles in `access.roles` are a coarse filter "who can call the endpoint at all"; the fine check (who creates whom) is done by the model validator:

```yaml
createSomething:
  create:
    comment: "A short description of the behavior and access rules."
    access:
      authRequired: true
      roles: ["ROLE_OWNER", "ROLE_ADMIN"]
    include:
      - result
```

The semantics of `include` and `writable` on writing actions (the full description —
[domains/actions.md](../domains/actions.md), "writable, include, includable and the `included` parameter"):

- `writable` — input: what the client can declare in `?included=` and
  pass in the body. The declaration is mandatory: a relation in the body without `?included=` → 422,
  a declared relation without a body → 422. Declaration ∩ `writable` is written.
- `include` — output: the listed relations are always in the response, no query is needed. This is how
  the client reads the result of the saver's work (for example, `marker.statusHistory`).
- A relation that the server fills itself is described **only** in `include`.
  It is not added to `writable`: `writable` is input, and such an entry would open
  writing into an output relation to the client.

`impl.savers` — registering the saver:

```yaml
createSomethingSaver: 'App\Api\Savers\CreateSomething'
```

`impl.models` — mapping the model onto the class with getters/setters:

```yaml
createSomething:
  class: 'App\Api\Model\CreateSomething'
  attributes:
    id: { getter: getId }
    email: { getter: email, setter: email }
    role: { getter: role, setter: role }
    password: { getter: password, setter: password }
  relations:
    result: { getter: result, setter: result }
```

`impl.actions` — binding the action to the saver:

```yaml
createSomething:
  create:
    entitySaver: createSomethingSaver
```

### 5. Tests

A feature file in the style of the existing ones (`auth.feature`, `reset_password.feature`): scenarios for success, for each domain rule (taken email → 422, insufficient rights → 422/403), for an unauthenticated request (403). Fields computed by the server are checked via `the item field ... is not null`; side effects in the DB — with a separate context step that reads the record directly via `EntityManager`.

## Checklist

- [ ] Creation is really non-trivial (computed fields / contract mismatch with the entity / domain rules / side effects) — otherwise a DTO is not needed.
- [ ] Model: nullable properties, `getId()`, validation attributes.
- [ ] Response fields are marked `hide` only if they must not be shown.
- [ ] Saver: the input type check comes first; a taken unique key — before `flush()`.
- [ ] Exceptions are reused from `Api/Exception`, the codes are correct (422/403/401/500).
- [ ] The config is extended in four places: `schema.models`, `schema.actions`, `impl.savers`/`impl.models`, `impl.actions`.
- [ ] Feature tests: success, each rule, an unauthenticated request, checking side effects in the DB.
