<?php

declare(strict_types=1);

namespace UniMethod\Frame\Constraint;

enum RuleScope: string
{
    case Value = 'value';
    case Collection = 'collection';
}
