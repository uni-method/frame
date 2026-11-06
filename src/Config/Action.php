<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

use UniMethod\Frame\Config\ListAction\ListActionConfig;

final readonly class Action
{
    /**
     * @param ActionType $type Action type
     * @param string $model Model (type from Model)
     * @param array<string, WritableConfig>|null $writable
     *      Relations that can be modified on create/update.
     *      Key — a path in dot notation (for example, "author.address.city"),
     *      value — the write mode and optional access restrictions.
     *      If access inside WritableConfig is null — it is inherited from the action's $access.
     * @param list<string>|null $include Relations included in the response by default (dot notation is supported)
     * @param array<string, AccessConfig|null>|null $includable
     *      Relations available for request via the include parameter.
     *      Key — a path in dot notation, value — access restrictions.
     *      If the value is null — it is inherited from the action's $access.
     *      If the property is null — there are no restrictions on requested includes.
     * @param ListActionConfig|null $config If \UniMethod\Frame\Config\ActionType::List then we look at the settings
     * @param string|null $comment Comment
     * @param AccessConfig|null $access Authorization and role restrictions
     * @param string|null $entityLocator Locator key (for read, delete)
     * @param string|null $entitySaver Saver key (for create, update)
     * @param string|null $entityRemover Remover key (for delete)
     */
    public function __construct(
        public ActionType $type,
        public string $model,
        public ?array $writable = null,
        public ?array $include = null,
        public ?array $includable = null,
        public ?ListActionConfig $config = null,
        public ?string $comment = null,
        public ?AccessConfig $access = null,
        public ?string $entityLocator = null,
        public ?string $entitySaver = null,
        public ?string $entityRemover = null,
    ) {
    }
}
