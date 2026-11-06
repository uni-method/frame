<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * An operator DTO that counts constructor calls.
 *
 * Операторный DTO, считающий вызовы конструктора.
 */
final class ProductEqual
{
    public static int $constructed = 0;

    public function __construct(public ?string $name = null)
    {
        self::$constructed++;
    }
}
