<?php

declare(strict_types=1);

namespace UniMethod\Frame\Exception;

use Throwable;

/**
 * Marker of a frame configuration exception.
 *
 * Implemented by exceptions that mean a mismatch between the config, impl,
 * application implementations (locator/saver/remover/repository, entity
 * getters/setters) and the ApiService assembly. They are not determined by client input,
 * `ApiService::execute` does not catch them — they fly to the application boundary,
 * which produces a 500 and may handle this category separately.
 *
 * Extends Throwable to statically guarantee that the implementing class is an
 * exception and not an arbitrary object.
 *
 * Маркер исключения конфигурации фрейма.
 *
 * Реализуется исключениями, которые означают рассогласование конфига, impl,
 * прикладных реализаций (locator/saver/remover/repository, геттеры/сеттеры
 * сущностей) и сборки ApiService между собой. Вводом клиента не определяются,
 * `ApiService::execute` их не ловит — они улетают на границу приложения,
 * которая оформляет 500 и может обработать эту категорию отдельно.
 *
 * Расширяет Throwable, чтобы статически гарантировать: реализующий класс — это
 * исключение, а не произвольный объект.
 */
interface FrameConfigurationException extends Throwable
{
}
