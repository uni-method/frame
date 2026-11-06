<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

/**
 * It must have its own cache, since it will be used in several services
 * within a single request and will make DB queries
 *
 * У него должен быть свой кеш, так как он будет использоваться в нескольких сервисах
 * в рамках одного реквеста и будет делать запросы к бд
 */
interface EntityLocatorInterface
{
    /**
     * @param list<string> $include List of relation paths for eager loading (for example: ['profile', 'posts.tags'])
     * @param ?object $object Raw data passed from the request body (passed only in PATCH) so that the dto can be
     *                        filled from it when there is an id but, for example, the type is missing to
     *                        unambiguously determine which data to fill the DTO from
     */
    public function find(
        string $className,
        int|string $id,
        array $include = [],
        ?object $object = null,
    ): object|null;
}
