<?php

declare(strict_types=1);

namespace UniMethod\Frame\Exception;

use Throwable;

/**
 * Marker of a frame domain exception.
 *
 * Implemented by exceptions that are determined by client input and that
 * are safe and meaningful to show in the response (4xx). `ApiService::execute` catches
 * exactly this type and turns it into ApiResult::error; everything else
 * (infrastructure failures, configuration bugs, non-frame exceptions) is not
 * caught and flies to the application boundary for logging and producing a 500.
 *
 * Extends Throwable to statically guarantee that the implementing class is an
 * exception and not an arbitrary object.
 *
 * Маркер доменного исключения фрейма.
 *
 * Реализуется исключениями, которые детерминированы вводом клиента и которые
 * безопасно и осмысленно показать в ответе (4xx). `ApiService::execute` ловит
 * именно этот тип и превращает его в ApiResult::error; всё остальное
 * (инфраструктурные сбои, баги конфигурации, не-фреймовые исключения) не
 * ловится и улетает на границу приложения для логирования и оформления 500.
 *
 * Расширяет Throwable, чтобы статически гарантировать: реализующий класс — это
 * исключение, а не произвольный объект.
 */
interface FrameDomainException extends Throwable
{
    /**
     * The HTTP code under which this domain exception is shown to the client.
     * Values are taken from UniMethod\Frame\Integration\Errors.
     *
     * HTTP-код, под которым это доменное исключение показывается клиенту.
     * Значения берутся из UniMethod\Frame\Integration\Errors.
     */
    public function getHttpErrorCode(): int;
}
