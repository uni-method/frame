<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

enum SchemaWriteMode: string
{
    /**
     * The attribute/relation is read-only; there is no setter
     *
     * Атрибут/связь доступны только для чтения; setter отсутствует
     */
    case ReadOnly = "false";

    /**
     * The attribute/relation is writable
     *
     * Атрибут/связь доступны для записи
     */
    case Write = "true";

    /**
     * The attribute/relation is set only via the constructor
     *
     * Атрибут/связь устанавливается только через конструктор
     */
    case CreateOnly = "create-only";
}
