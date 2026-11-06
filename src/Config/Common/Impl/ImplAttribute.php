<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config\Common\Impl;

final readonly class ImplAttribute
{
    /**
     * @param string $name Attribute name — the key for matching with SchemaAttribute
     * @param string|null|false $getter Method/property for reading; false — do not use; null — auto-detection
     * @param string|null|false $setter Method/property for writing; false — do not use; null — auto-detection
     * @param string|null|false $mutatorSetter Method/property for writing by a mutator;
     *     false — do not use; null — not used
     * @param bool $constructorParam Passed via the constructor
     */
    public function __construct(
        public string $name,
        public string|null|false $getter = null,
        public string|null|false $setter = null,
        public string|null|false $mutatorSetter = null,
        public bool $constructorParam = false,
    ) {
    }
}
