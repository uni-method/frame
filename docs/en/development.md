# Framework development

For contributors to `UniMethod\Frame`: environment, pre-commit checks, test layout.

## Environment

PHP 8.3: the minimum supported version. PHP 8.4 syntax (calling a method on `new` without parentheses, property hooks, etc.) is not used; PHP 8.4 API — only behind a `PHP_VERSION_ID` check (`ReflectionProperty::isLazy()`). Syntax compatibility is checked by `composer ci:php-lint`.

## CI

All checks in CI mode (without modifying files) — with one command:

```shell
composer ci
```

Individually:

| Script                     | Command                                               |
| -------------------------- | ----------------------------------------------------- |
| `composer ci:php-cs-fixer` | `php-cs-fixer fix --dry-run --diff`                   |
| `composer ci:phpcs`        | `phpcs`                                               |
| `composer ci:phpstan`      | `phpstan analyse -c phpstan.neon --memory-limit=2G`   |
| `composer ci:rector`       | `rector process --dry-run`                            |
| `composer ci:phpunit`      | `phpunit tests`                                       |
| `composer ci:php-lint`     | `php -l` over `src` and `tests` in the `php:8.3-cli-alpine` image (requires Docker; not part of `composer ci`) |

The commands below run the tools manually, including with fixing files.

## Code style

```shell
vendor/bin/php-cs-fixer fix
```

```shell
vendor/bin/phpcbf
```

## Static analysis

```shell
php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon
```

## Refactoring

```shell
vendor/bin/rector process
```

## Tests

```shell
php vendor/phpunit/phpunit/phpunit tests
```

Coverage:

```shell
XDEBUG_MODE=coverage php vendor/phpunit/phpunit/phpunit -c phpunit.coverage.xml tests
```

```shell
XDEBUG_MODE=coverage php vendor/phpunit/phpunit/phpunit \
  -c phpunit.coverage.xml \
  --coverage-text
```

```shell
XDEBUG_MODE=coverage php vendor/phpunit/phpunit/phpunit \
  -c phpunit.coverage.xml \
  --coverage-clover=coverage.xml
```

## Test layout

- A test lives next to the code it checks: `tests/Unit/<Directory>`
  mirrors `src/<Directory>`, one test class — one class under test or
  an aspect of it (`ActionValidatorWriteModesTest`, `ActionValidatorDeclarationTest`).
- A test's docblock describes the contract ("what should be"), not the history of a bug.
- Shared stubs live in `tests/Unit/Stub` with domain names (`Post`, `Writer`,
  `RecordingLocator`); one class per role, no duplicates.
