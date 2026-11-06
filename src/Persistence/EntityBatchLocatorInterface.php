<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

/**
 * Optional locator extension: loading several entities with one
 * query. Used by list filters by relation (`filter[in][author][]=…`):
 * if the locator of the target model's read action implements this interface,
 * MappingToListAction calls findMany() once instead of find() for each id.
 *
 * Необязательное расширение локатора: загрузка нескольких сущностей одним
 * запросом. Используется фильтрами списков по связи (`filter[in][author][]=…`):
 * если локатор read-действия целевой модели реализует этот интерфейс,
 * MappingToListAction вызывает findMany() один раз вместо find() на каждый id.
 */
interface EntityBatchLocatorInterface extends EntityLocatorInterface
{
    /**
     * @param list<int|string> $ids Unique ids; missing ones simply do not get into the result
     * @param list<string> $include Relation paths for eager loading
     * @return list<object> Found entities in any order
     */
    public function findMany(string $className, array $ids, array $include = []): array;
}
