# Разработка фреймворка

Для контрибьютора `UniMethod\Frame`: окружение, проверки перед коммитом, устройство тестов.

## Окружение

PHP 8.3: минимальная поддерживаемая версия. Синтаксис PHP 8.4 (вызов метода на `new` без скобок, property hooks и т.п.) не используется; API 8.4 — только за проверкой `PHP_VERSION_ID` (`ReflectionProperty::isLazy()`). Совместимость синтаксиса проверяет `composer ci:php-lint`.

## CI

Все проверки в режиме CI (без изменения файлов) — одной командой:

```shell
composer ci
```

По отдельности:

| Скрипт                     | Команда                                               |
| -------------------------- | ----------------------------------------------------- |
| `composer ci:php-cs-fixer` | `php-cs-fixer fix --dry-run --diff`                   |
| `composer ci:phpcs`        | `phpcs`                                               |
| `composer ci:phpstan`      | `phpstan analyse -c phpstan.neon --memory-limit=2G`   |
| `composer ci:rector`       | `rector process --dry-run`                            |
| `composer ci:phpunit`      | `phpunit tests`                                       |
| `composer ci:php-lint`     | `php -l` по `src` и `tests` в образе `php:8.3-cli-alpine` (нужен Docker; в `composer ci` не входит) |

Команды ниже — ручной запуск инструментов, в том числе с исправлением файлов.

## Стиль кода

```shell
vendor/bin/php-cs-fixer fix
```

```shell
vendor/bin/phpcbf
```

## Статический анализ

```shell
php -d memory_limit=2G vendor/bin/phpstan analyse -c phpstan.neon
```

## Рефакторинг

```shell
vendor/bin/rector process
```

## Тесты

```shell
php vendor/phpunit/phpunit/phpunit tests
```

Покрытие:

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

## Устройство тестов

- Тест живёт рядом с кодом, который проверяет: `tests/Unit/<Каталог>`
  повторяет `src/<Каталог>`, один тестовый класс — один проверяемый класс или
  его аспект (`ActionValidatorWriteModesTest`, `ActionValidatorDeclarationTest`).
- Docblock теста описывает контракт («что должно быть»), а не историю бага.
- Общие стабы — в `tests/Unit/Stub` с предметными именами (`Post`, `Writer`,
  `RecordingLocator`); один класс на роль, без дублей.
