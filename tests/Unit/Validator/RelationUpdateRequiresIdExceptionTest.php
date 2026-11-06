<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Validator\Exception\RelationUpdateRequiresIdException;

final class RelationUpdateRequiresIdExceptionTest extends TestCase
{
    public function testConstructorSetsCorrectMessage(): void
    {
        // Arrange
        //
        // Подготовка
        $path = "testPath";
        $expectedMessage =
            "Relation 'testPath' with mode 'update' requires an 'id' field.";

        // Act
        //
        // Действие
        $exception = new RelationUpdateRequiresIdException($path);

        // Assert
        //
        // Проверка
        $this->assertEquals($expectedMessage, $exception->getMessage());
    }
}
