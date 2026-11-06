<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * Internal signal: a filter field from the query is not matched to the model schema
 * (there is no such attribute/relation along the path). Used only inside filter
 * parsing, where such a field is silently skipped. NOT a domain one (not shown
 * to the client) and must not leave MappingToListAction — it is caught locally.
 *
 * Внутренний сигнал: поле фильтра из query не сопоставлено со схемой модели
 * (нет такого атрибута/связи по пути). Используется только внутри разбора
 * фильтров, где такое поле молча пропускается. НЕ доменное (не показывается
 * клиенту) и не должно покидать MappingToListAction — ловится локально.
 */
final class UnknownFilterFieldException extends RuntimeException implements
    FrameConfigurationException
{
}
