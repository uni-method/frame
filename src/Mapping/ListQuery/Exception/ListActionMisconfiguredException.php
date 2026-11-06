<?php

declare(strict_types=1);

namespace UniMethod\Frame\Mapping\ListQuery\Exception;

use RuntimeException;
use UniMethod\Frame\Exception\FrameConfigurationException;

/**
 * Misconfiguration of a list action: repository is not set/invalid, the root class is not set or
 * not generated, config is missing, etc. This is an application assembly error,
 * not the client's fault — NOT a domain exception: it does not implement
 * FrameDomainException, is not caught in ApiService::execute, flies to the boundary
 * and is produced as a 500 with the trace logged.
 *
 * Мисконфигурация list-действия: не задан/некорректен repository, не задан или
 * не сгенерирован root-класс, отсутствует config и т.п. Это ошибка сборки
 * приложения, а не вина клиента — НЕ доменное исключение: не реализует
 * FrameDomainException, не ловится в ApiService::execute, улетает на границу
 * и оформляется как 500 с логированием трейса.
 */
final class ListActionMisconfiguredException extends RuntimeException implements
    FrameConfigurationException
{
}
