<?php

declare(strict_types=1);

namespace UniMethod\Frame\Type;

enum ValueKind: string
{
    case String = 'string';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case DateTime = 'datetime';
    case Uuid = 'uuid';
}
