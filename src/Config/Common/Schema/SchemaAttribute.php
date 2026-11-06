<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

use UniMethod\Frame\Config\Mutators;

final readonly class SchemaAttribute
{
    /**
     * @param string $name Attribute name
     * @param string $type Attribute type (string, int, datetime, ...)
     * @param SchemaWriteMode|null $writeMode Write semantics; null — not restricted by the schema
     * @param Mutators|null $mutators Mutators by phase
     * @param string|null $comment Comment
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?SchemaWriteMode $writeMode = null,
        public ?Mutators $mutators = null,
        public ?string $comment = null,
    ) {
    }
}
