<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Mapping;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Mapping\Exception\UpdateRequiresIdException;

class UpdateRequiresIdExceptionTest extends TestCase
{
    public function testConstructSetsCorrectMessage(): void
    {
        // Arrange
        //
        // Подготовка
        $modelType = "ExampleModel";
        $pkField = "id";
        $expectedMessage =
            "Update mode for model 'ExampleModel' requires either rootId parameter or 'id' field in data";

        // Act
        //
        // Действие
        $exception = new UpdateRequiresIdException($modelType, $pkField);

        // Assert
        //
        // Проверка
        $this->assertEquals($expectedMessage, $exception->getMessage());
    }
}
