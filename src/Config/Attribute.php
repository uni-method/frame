<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

final readonly class Attribute
{
    /**
     * @param string|null|false $getter Method/property for reading. false = do not use, null = auto-detection
     * @param string|null|false $setter Method/property for writing. false = do not use, null = auto-detection
     * @param string|null|false $mutatorSetter Method/property for writing by a mutator.
     *     false = do not use, null = not used
     */
    public function __construct(
        public string $name,
        public string $type,
        public string|null|false $getter = null,
        public string|null|false $setter = null,
        public string|null|false $mutatorSetter = null,
        public ?string $comment = null,
        public bool $constructorParam = false,
        public ?Mutators $mutators = null,
    ) {
    }
}
