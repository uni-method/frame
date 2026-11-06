<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Validator\Exception\DtoReferencedException;

final class DtoReferencedExceptionTest extends TestCase
{
    public function testConstructorSetsMessageCorrectly(): void
    {
        // Arrange
        //
        // Подготовка
        $modelType = "testModel";
        $path = "/test/path";
        $mode = "testMode";
        $expectedMessage =
            "Model 'testModel' at path '/test/path' has no entity locator (DTO). Mode 'testMode' requires " .
            "persistence. Set impl.models[testModel].entityLocator, a read action with entityLocator or " .
            "impl.defaultLocator";

        // Act
        //
        // Действие
        $exception = new DtoReferencedException($modelType, $path, $mode);

        // Assert
        //
        // Проверка
        $this->assertSame($expectedMessage, $exception->getMessage());
    }
}
