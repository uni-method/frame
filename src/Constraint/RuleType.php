<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

enum RuleType: string
{
    case NotNull = 'notNull';
    case NotBlank = 'notBlank';
    case Length = 'length';
    case Regex = 'regex';
    case Email = 'email';
    case Url = 'url';
    case Range = 'range';
    case Choice = 'choice';
    case Equals = 'equals';
    case Count = 'count';
    case UniqueItems = 'uniqueItems';
}
