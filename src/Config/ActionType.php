<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

enum ActionType: string
{
    case Create = 'create';
    case Read = 'read';
    case List = 'list';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * Checks whether this action type can modify relations
     *
     * Проверяет, может ли этот тип действия изменять связи
     */
    public function canWrite(): bool
    {
        return match ($this) {
            self::Create, self::Update, self::Delete => true,
            self::Read, self::List => false,
        };
    }
}
