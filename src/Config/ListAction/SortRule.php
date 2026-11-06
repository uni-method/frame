<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\ListAction;

final readonly class SortRule
{
    public function __construct(
        /**
         * Nested path to the field, for example "address.city"
         *
         * Вложенный путь к полю, например "address.city"
         */
        public string $path,
        /**
         * Default direction if the client did not specify one
         *
         * Направление по умолчанию, если клиент не указал
         */
        public ?SortDirection $defaultDirection = null,
        /**
         * Whether sorting by this field is required
         *
         * Обязательно ли сортировать по этому полю
         */
        public bool $required = false,
        /**
         * Default value that will be applied
         * if the client did not pass the sort[...] parameter at all.
         * For example: ['path' => 'createdAt', 'direction' => 'desc']
         *
         * Значение по умолчанию, которое будет применено,
         * если клиент не передал параметр sort[...] вообще.
         * Например: ['path' => 'createdAt', 'direction' => 'desc']
         */
        public ?DefaultSortValue $defaultValue = null,
        /**
         * Arbitrary comment for documentation
         *
         * Произвольный комментарий для документации
         */
        public ?string $comment = null,
    ) {
    }
}
