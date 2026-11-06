<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

enum RelationWriteMode: string
{
    /**
     * Use the existing relation without changes
     *
     * Использовать существующую связь без изменений
     */
    case Reference = 'reference';

    /**
     * Create a new related entity and set the relation
     *
     * Создать новую связанную сущность и установить связь
     */
    case Create = 'create';

    /**
     * Use the existing relation by id or create a new one if more than just the id is passed
     *
     * Использовать существующую связь по id либо создать новую, если передан не только id
     */
    case ReferenceOrCreate = 'reference_or_create';
}
