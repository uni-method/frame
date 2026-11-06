<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameDomainException;
use UniMethod\Frame\Integration\Errors;

/**
 * A filter by relation refers to entities that do not exist (`filter[equal][author]=404`
 * or all ids in `in` are unknown). A selection by such a filter is knowingly empty:
 * ApiService responds with 200 and an empty list without calling the repository.
 * Outside ApiService (the mapper is called directly) the exception means 404.
 *
 * Фильтр по связи ссылается на сущности, которых нет (`filter[equal][author]=404`
 * или все id в `in` неизвестны). Выборка по такому фильтру заведомо пуста:
 * ApiService отвечает 200 с пустым списком, не обращаясь к репозиторию.
 * Вне ApiService (маппер вызван напрямую) исключение означает 404.
 */
final class UnsatisfiableListFilterException extends RuntimeException implements
    FrameDomainException
{
    /**
     * @param list<int|string> $missingIds
     */
    public function __construct(
        public readonly string $modelType,
        public readonly string $filterPath,
        public readonly array $missingIds,
    ) {
        parent::__construct(
            sprintf(
                "Filter '%s' refers to %s '%s' that do not exist: %s",
                $filterPath,
                $modelType,
                "id",
                implode(", ", array_map(strval(...), $missingIds)),
            ),
        );
    }

    public function getHttpErrorCode(): int
    {
        return Errors::NOT_FOUND_ERROR_CODE;
    }
}
