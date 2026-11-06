<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

use UniMethod\Frame\Persistence\EntityLocatorInterface;

/**
 * Locator: returns the given entity and records find() calls.
 *
 * Локатор: отдаёт заданную сущность и запоминает вызовы find().
 */
final class RecordingLocator implements EntityLocatorInterface
{
    public int $findCalls = 0;

    /** @var list<string> */
    public array $lastInclude = [];

    public ?string $lastClassName = null;

    public function __construct(private readonly ?object $entity = null)
    {
    }

    public function find(
        string $className,
        int|string $id,
        array $include = [],
        ?object $object = null,
    ): object|null {
        $this->findCalls++;
        $this->lastInclude = $include;
        $this->lastClassName = $className;

        return $this->entity;
    }
}
