<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

final readonly class Relation
{
    public const string ONE_TO_MANY = "oneToMany";

    public const string MANY_TO_MANY = "manyToMany";

    public const string MANY_TO_ONE = "manyToOne";

    public const string ONE_TO_ONE = "oneToOne";

    /**
     * @param string $name Relation name
     * @param string $type Relation type: oneToOne, oneToMany, manyToOne, manyToMany
     * @param string $targetModel Target model (type from Model)
     * @param string|null|false $getter Method/property for reading. false = do not use, null = auto-detection
     * @param string|null|false $setter Method/property for writing. false = do not use, null = auto-detection
     * @param string|null|false $add Method for adding an element (for *ToMany).
     *     false = do not use, null = auto-detection
     * @param string|null|false $mutatorSetter Method/property for writing by a mutator.
     *     false = do not use, null = not used
     * @param string|null $comment Comment
     * @param bool $constructorParam Passed as a model parameter
     * @param Mutators|null $mutators Mutators for the relation
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $targetModel,
        public string|null|false $getter = null,
        public string|null|false $setter = null,
        public string|null|false $add = null,
        public string|null|false $mutatorSetter = null,
        public ?string $comment = null,
        public bool $constructorParam = false,
        public ?Mutators $mutators = null,
    ) {
    }
}
