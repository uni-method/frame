<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

enum PhaseType: string
{
    case AfterInput = 'afterInput';
    case AfterValidate = 'afterValidate';
    case BeforeOutput = 'beforeOutput';
}
