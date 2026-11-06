<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Persistence\RemoverNotConfiguredException;

final class RemoverNotConfiguredExceptionTest extends TestCase
{
    public function testConstructorSetsMessageCorrectly(): void
    {
        // Arrange
        //
        // Подготовка
        $modelType = 'TestModel';
        $expectedMessage = "Remover is not configured for model 'TestModel'.";

        // Act
        //
        // Действие
        $exception = new RemoverNotConfiguredException($modelType);

        // Assert
        //
        // Проверка
        $this->assertEquals($expectedMessage, $exception->getMessage());
    }
}
