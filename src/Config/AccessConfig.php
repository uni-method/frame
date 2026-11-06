<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

final readonly class AccessConfig
{
    /**
     * @param bool $authRequired Whether authorization is required
     * @param string[]|null $roles Allowed roles (if null — no role restrictions)
     */
    public function __construct(
        public bool $authRequired = false,
        public ?array $roles = null,
    ) {
    }

    public function isTrivial(): bool
    {
        return $this->authRequired === false && $this->roles === null;
    }
}
