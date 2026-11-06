<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Stub;

/**
 * The post entity: attributes id, title; relation author → Writer.
 *
 * Сущность post: атрибуты id, title; связь author → Writer.
 */
final class Post
{
    public function __construct(
        public ?string $id = null,
        public ?string $title = null,
        public ?Writer $author = null,
    ) {
    }
}
