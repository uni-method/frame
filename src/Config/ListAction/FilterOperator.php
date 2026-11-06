<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

enum FilterOperator: string
{
    case Equal = 'equal';
    case In = 'in';
    case Like = 'like';
    case Range = 'range';
}
