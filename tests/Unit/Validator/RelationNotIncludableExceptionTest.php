<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Validator;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Validator\Exception\RelationNotIncludableException;

class RelationNotIncludableExceptionTest extends TestCase
{
    public function testConstructorSetsMessageCorrectly(): void
    {
        // Arrange
        //
        // Подготовка
        $modelType = "User";
        $relationPath = "posts";
        $actionType = "fetch";

        // Act
        //
        // Действие
        $exception = new RelationNotIncludableException(
            $modelType,
            $relationPath,
            $actionType,
        );

        // Assert
        //
        // Проверка
        $expectedMessage =
            "Relation 'posts' in model 'User' is not allowed for inclusion in action 'fetch'. Check 'include' field " .
            "in action configuration.";
        $this->assertEquals($expectedMessage, $exception->getMessage());
    }
}
