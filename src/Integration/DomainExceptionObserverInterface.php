<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

use UniMethod\Frame\Exception\FrameDomainException;

/**
 * Optional extension point for observing domain exceptions.
 *
 * Domain exceptions (4xx) are a normal flow, not a failure, so by
 * default they are not logged: ApiService turns them into ApiResult::error without
 * side effects. If the application needs to record them (analytics, audit,
 * counters of malformed requests), it passes an implementation of this interface to
 * ApiService. If none is passed, domain exceptions are not logged.
 *
 * Failures (5xx, non-domain) do NOT get here: they are not caught in execute and
 * are logged at the application boundary with the full trace.
 *
 * Необязательная точка расширения для наблюдения за доменными исключениями.
 *
 * Доменные исключения (4xx) — это нормальный поток, а не сбой, поэтому по
 * умолчанию они не логируются: ApiService превращает их в ApiResult::error без
 * побочных эффектов. Если приложению нужно их фиксировать (аналитика, аудит,
 * счётчики кривых запросов), оно передаёт реализацию этого интерфейса в
 * ApiService. Не передан — логирование доменных не происходит.
 *
 * Сбои (5xx, не-доменные) сюда НЕ попадают: они не ловятся в execute и
 * логируются на границе приложения с полным трейсом.
 */
interface DomainExceptionObserverInterface
{
    public function onDomainException(FrameDomainException $exception): void;
}
