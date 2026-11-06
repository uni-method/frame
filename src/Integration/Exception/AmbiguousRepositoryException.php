<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * More than one repository matching the class from
 * `config.repository` (instanceof) was passed to ApiService: the framework cannot choose which one
 * serves the list action. Misconfiguration → 500 (caught at construction).
 *
 * В ApiService передано больше одного репозитория, подходящего под класс из
 * `config.repository` (instanceof): фреймворк не может выбрать, какой из них
 * обслуживает list-действие. Мисконфиг → 500 (ловится на конструировании).
 */
final class AmbiguousRepositoryException extends RuntimeException implements
    FrameConfigurationException
{
    /**
     * @param list<class-string> $candidates
     */
    public function __construct(string $configuredClass, string $modelType, array $candidates)
    {
        parent::__construct(sprintf(
            "List action of model '%s' expects one repository of %s, but %d were passed to ApiService: %s",
            $modelType,
            $configuredClass,
            count($candidates),
            implode(", ", $candidates),
        ));
    }
}
