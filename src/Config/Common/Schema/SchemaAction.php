<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Schema;

use UniMethod\Frame\Config\{AccessConfig, ActionType, WritableConfig};

final readonly class SchemaAction
{
    /**
     * @param ActionType $type Action type
     * @param string $model Model identifier
     * @param AccessConfig|null $access Access restrictions
     * @param array<string, WritableConfig>|null $writable Relations available for writing
     * @param list<string>|null $include Relations included in the response by default
     * @param array<string, AccessConfig|null>|null $includable Relations available for request via include
     * @param SchemaListConfig|null $listConfig List action parameters
     * @param string|null $comment Comment
     */
    public function __construct(
        public ActionType $type,
        public string $model,
        public ?AccessConfig $access = null,
        public ?array $writable = null,
        public ?array $include = null,
        public ?array $includable = null,
        public ?SchemaListConfig $listConfig = null,
        public ?string $comment = null,
    ) {
    }
}
