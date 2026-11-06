<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Impl;

use UniMethod\Frame\Config\ActionType;

final readonly class ImplAction
{
    /**
     * @param ActionType $type Action type — the key for matching with SchemaAction
     * @param string $model Model identifier — the key for matching with SchemaAction
     * @param string|null $entityLocator Locator alias from ImplConfig::$locators
     * @param string|null $entitySaver Saver alias from ImplConfig::$savers
     * @param string|null $entityRemover Remover alias from ImplConfig::$removers
     * @param ImplListConfig|null $listConfig PHP-specific parameters of the list action
     */
    public function __construct(
        public ActionType $type,
        public string $model,
        public ?string $entityLocator = null,
        public ?string $entitySaver = null,
        public ?string $entityRemover = null,
        public ?ImplListConfig $listConfig = null,
    ) {
    }
}
