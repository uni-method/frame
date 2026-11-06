<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The root DTO of a list action of a model with the Product class.
 *
 * Корневой DTO list-действия модели с классом Product.
 */
final class ProductListRequest
{
    public ?object $filter = null;
}
