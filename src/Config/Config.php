<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

use UniMethod\Frame\Mutator\{
    AttributeMutatorInterface,
    RelationMutatorInterface,
};
use UniMethod\Frame\Persistence\{
    EntityLocatorInterface,
    EntityRemoverInterface,
    EntitySaverInterface,
};

/**
 * The main frame configuration.
 *
 * Главная конфигурация фрейма.
 */
final readonly class Config
{
    /**
     * @param Model[] $models List of entities with descriptions of attributes and relations to other models
     * @param Action[] $actions List of actions on models: CRUD + list
     * @param array<string, class-string<EntityLocatorInterface>> $locators References to locator classes for frameworks
     * @param array<string, class-string<EntitySaverInterface>> $savers References to saver classes for frameworks
     * @param array<string, class-string<EntityRemoverInterface>> $removers References to remover classes for frameworks
     * @param array<string, class-string<AttributeMutatorInterface>> $mutators References to attribute mutator classes
     * @param array<string, class-string<RelationMutatorInterface>> $relationMutators Relation mutators
     * @param string|null $userModel Reference to the user type
     * @param string|null $defaultLocator Locator alias for models without their own
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

    /**
     * Alias of the locator used to load the model by id (references, filters by
     * relation); null — there is nothing to load the model with. See ModelLocatorResolver.
     *
     * Алиас локатора, которым модель грузится по id (ссылки, фильтры по
     * связи); null — модель нечем загрузить. См. ModelLocatorResolver.
     */
    public function locatorForModel(string $modelType): ?string
    {
        return ModelLocatorResolver::resolve(
            $this->models,
            $this->actions,
            $this->defaultLocator,
            $modelType,
        );
    }
}
