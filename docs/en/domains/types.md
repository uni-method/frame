# Types

An attribute's `type` and a filter's `typeSpec` are parsed by `TypeSpecParser`. Recognized:

| Notation                                      | Kind       |
| --------------------------------------------- | ---------- |
| `string`                                      | string     |
| `int`, `integer`                              | integer    |
| `float`, `double`, `real`                     | fractional |
| `bool`, `boolean`                             | boolean    |
| `datetime`, `\DateTimeImmutable`, `\DateTime` | date-time  |
| `uuid`                                        | UUID       |

An input `datetime` value (request body and list action filters) is only
an absolute ISO 8601 (RFC 3339 profile): `2026-01-31`, `2026-01-31T10:15:00`,
with a fractional seconds part of up to 6 digits and/or an offset `Z` or `±hh:mm`
(`2026-01-31T10:15:00.000Z` — the `Date.prototype.toISOString()` format).
Relative values (`now`, `+1 day`), a space separator, zone abbreviations and
identifiers (`EST`, `Europe/Moscow`) are rejected (422 in the body, 400 in
a filter). A value **without an offset** is interpreted in the default PHP timezone
(`date.timezone` / `date_default_timezone_set()`); a date without time is midnight
in that timezone.

Modifiers:

- the `[]` suffix — a list (`string[]`, `int[]`);
- `|null` or `null|` — nullable (`string|null`, `int[]|null`).

The `[]` suffix works not only in a filter's `typeSpec` but also in the `type` of any
model attribute — for example `type: "int[]"` or `type: "string[]|null"`. The parser
is the same (`TypeSpecParser`), so the rules are identical.

Important: `[]` defines a list of elements of **one of the listed scalar kinds**
(string/int/float/bool/datetime/uuid). A list of objects or arbitrary maps
(for example a JSON structure with nested objects) cannot be expressed this way —
the parser does not know the types `array`, `json`, `object`, `mixed` and throws
`UnknownTypeException`. Data that on the entity side is an array of
objects or an associative map (a typical case is a field cast from JSONB
via a typecast) is not described as a model attribute: it should either be excluded from
the model or normalized into separate entities and described via relations
(`oneToMany`/`oneToOne`). A `*ToMany` relation is returned and accepted as a regular
PHP array of objects of the target model — see the section on relation getters/setters.

An unknown type in `typeSpec` leads to a loading error. A type that is not in
the list is not rejected at loading in a regular attribute `type`, but it is
used by the mapper/caster, so stick to the supported values.
