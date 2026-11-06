# UniMethod\Frame — документация

- [Философия](philosophy.md) — что делает фреймворк, что пишете вы, принципы.
- [Разработка](development.md) — для контрибьютора: окружение, проверки, устройство тестов.

## Домены

Справочник по частям фреймворка: конфиг и соответствующий ему прикладной код.

- [Конфиг](domains/config.md) — загрузчики, общая модель, валидация, ошибки парсинга, YAML.
- [Модели и сущности](domains/models.md) — модели, атрибуты, связи; entity-классы.
- [Действия](domains/actions.md) — `schema.actions`/`impl.actions`, `writable`/`include`/`includable`, `?included=`.
- [Доступ](domains/access.md) — `access`, `userModel`, провайдер пользователя.
- [list-запросы](domains/list.md) — `config` list-действия, генерируемые DTO.
- [Persistence](domains/persistence.md) — локаторы, сейверы, ремуверы, репозитории.
- [Мутаторы](domains/mutators.md) — преобразования значений по фазам.
- [Типы](domains/types.md) — типы атрибутов и их запись.
- [Ограничения](domains/constraints.md) — секция `constraints`, прибилд, проверка в рантайме.
- [Ошибки](domains/errors.md) — категории исключений, коды ответов, граница приложения.

## Использование

- [Начало работы](usage/getting-started.md) — сборка `ApiService`, формат запросов.
- [Добавление list-действия](usage/list-action.md)
- [Добавление DTO на создание](usage/create-dto.md)
- [Выбор стратегии сохранения](usage/persistence-strategy.md)
- [Паттерны ограничений](usage/validation-patterns.md)
