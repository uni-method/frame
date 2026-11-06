<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

use Closure;
use UniMethod\Frame\Persistence\EntityCollectionInterface;

/**
 * List action repository: returns the given items and the count, records
 * call arguments. Not final — tests extend it, imitating a DI lazy proxy.
 *
 * Репозиторий list-действия: отдаёт заданные элементы и счётчик, запоминает
 * аргументы вызовов. Не final — тесты наследуют его, имитируя lazy-прокси DI.
 */
class RecordingRepository implements EntityCollectionInterface
{
    /** @var list<array{filter: ?object, sort: ?object, pagination: ?object, included: list<string>}> */
    public array $listCalls = [];

    /** @var list<array{filter: ?object}> */
    public array $countCalls = [];

    /** @var list<object> */
    public array $returnItems = [];

    public int $returnCount = 0;

    public ?Closure $onList = null;

    public ?Closure $onCountForList = null;

    public function list(
        object|null $filter = null,
        object|null $sort = null,
        object|null $pagination = null,
        array $included = [],
    ): array {
        $this->listCalls[] = [
            "filter" => $filter,
            "sort" => $sort,
            "pagination" => $pagination,
            "included" => $included,
        ];
        if ($this->onList instanceof Closure) {
            ($this->onList)();
        }

        return $this->returnItems;
    }

    public function countForList(object|null $filter = null): int
    {
        $this->countCalls[] = ["filter" => $filter];
        if ($this->onCountForList instanceof Closure) {
            ($this->onCountForList)();
        }

        return $this->returnCount;
    }
}
