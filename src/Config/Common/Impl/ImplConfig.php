<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Impl;

final readonly class ImplConfig
{
    /**
     * @param ImplModel[] $models
     * @param ImplAction[] $actions
     * @param array<string, class-string>|array<mixed> $locators
     * @param array<string, class-string>|array<mixed> $savers
     * @param array<string, class-string>|array<mixed> $removers
     * @param array<string, class-string>|array<mixed> $mutators
     * @param array<string, class-string>|array<mixed> $relationMutators
     */
    public function __construct(
        public array $models = [],
        public array $actions = [],
        public array $locators = [],
        public array $savers = [],
        public array $removers = [],
        public array $mutators = [],
        public array $relationMutators = [],
        public ?string $userModel = null,
        public ?string $defaultLocator = null,
    ) {
    }
}
