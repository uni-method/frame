<?php

declare(strict_types=1);

namespace UniMethod\Frame\Persistence;

interface EntitySaverInterface
{
    /**
     * Saves the object and MUST return the same instance (`$objectToSave`),
     * not a re-read/new entity or a DTO.
     *
     * The framework relies on identity: BeforeOutput phase mutators
     * are applied to the object that is then mapped into the response to the client.
     * A different instance is a violation of the integration contract: ApiService throws
     * SaverContractViolationException (misconfiguration, 500), not a domain
     * error.
     *
     * Сохраняет объект и ОБЯЗАН вернуть тот же самый экземпляр (`$objectToSave`),
     * а не перечитанную/новую сущность или DTO.
     *
     * Фреймворк полагается на идентичность: мутаторы фазы BeforeOutput
     * применяются к объекту, который затем маппится в ответ клиенту.
     * Другой экземпляр — нарушение контракта интеграции: ApiService бросает
     * SaverContractViolationException (мисконфигурация, 500), а не доменную
     * ошибку.
     */
    public function save(object $objectToSave): object;
}
