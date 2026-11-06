<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The root DTO of a list action of a model with the Order class.
 *
 * Корневой DTO list-действия модели с классом Order.
 */
final class OrderListRequest
{
    public ?object $filter = null;
}
