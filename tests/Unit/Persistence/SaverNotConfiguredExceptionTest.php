<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Persistence\SaverNotConfiguredException;

final class SaverNotConfiguredExceptionTest extends TestCase
{
    public function testConstructorSetsCorrectMessage(): void
    {
        // Arrange
        //
        // Подготовка
        $modelType = 'ExampleModel';

        // Act
        //
        // Действие
        $exception = new SaverNotConfiguredException($modelType);

        // Assert
        //
        // Проверка
        $this->assertEquals("Saver is not configured for model 'ExampleModel'.", $exception->getMessage());
    }
}
