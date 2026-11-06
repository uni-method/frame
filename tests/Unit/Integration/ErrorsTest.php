<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Integration\{Error, Errors};

class ErrorsTest extends TestCase
{
    public function testToArrayWithNoErrorsReturnsEmptyArray(): void
    {
        // Arrange
        //
        // Подготовка
        $errors = new Errors();

        // Act
        //
        // Действие
        $result = $errors->toArray();

        // Assert
        //
        // Проверка
        $this->assertEquals(["errors" => []], $result);
    }

    public function testToArrayWithOneErrorReturnsArrayOfErrorArrays(): void
    {
        // Arrange
        //
        // Подготовка
        $errorMock = new Error(400, "Test error");
        $errors = new Errors($errorMock);

        // Act
        //
        // Действие
        $result = $errors->toArray();

        // Assert
        //
        // Проверка
        $this->assertEquals(
            [
                "errors" => [
                    ["status" => 400, "detail" => "Test error", "source" => []],
                ],
            ],
            $result,
        );
    }

    public function testJsonSerializeWithNoErrorsReturnsEmptyArray(): void
    {
        // Arrange
        //
        // Подготовка
        $errors = new Errors();

        // Act
        //
        // Действие
        $result = $errors->jsonSerialize();

        // Assert
        //
        // Проверка
        $this->assertEquals(["errors" => []], $result);
    }

    public function testJsonSerializeWithOneErrorReturnsArrayOfErrorArrays(): void
    {
        // Arrange
        //
        // Подготовка
        $errorMock = new Error(400, "Test error");
        $errors = new Errors($errorMock);

        // Act
        //
        // Действие
        $result = $errors->jsonSerialize();

        // Assert
        //
        // Проверка
        $this->assertEquals(
            [
                "errors" => [
                    ["status" => 400, "detail" => "Test error", "source" => []],
                ],
            ],
            $result,
        );
    }

    public function testGetStatusWithNoErrorsReturnsDefaultErrorCode(): void
    {
        // Arrange
        //
        // Подготовка
        $errors = new Errors();

        // Act
        //
        // Действие
        $result = $errors->getStatus();

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result);
    }

    public function testGetStatusWithAllSameStatusReturnsThatStatus(): void
    {
        // Arrange
        //
        // Подготовка
        $errorMock1 = new Error(404, "Not Found");
        $errorMock2 = new Error(404, "Not Found");
        $errors = new Errors($errorMock1, $errorMock2);

        // Act
        //
        // Действие
        $result = $errors->getStatus();

        // Assert
        //
        // Проверка
        $this->assertEquals(404, $result);
    }

    public function testGetStatusWithDifferentStatusesReturnsDefaultErrorCode(): void
    {
        // Arrange
        //
        // Подготовка
        $errorMock1 = new Error(404, "Not Found");
        $errorMock2 = new Error(500, "Internal Server Error");
        $errors = new Errors($errorMock1, $errorMock2);

        // Act
        //
        // Действие
        $result = $errors->getStatus();

        // Assert
        //
        // Проверка
        $this->assertEquals(400, $result);
    }

    public function testGetStatusWithOneErrorReturnsItsStatus(): void
    {
        // Arrange
        //
        // Подготовка
        $errorMock = new Error(422, "Unprocessable Entity");
        $errors = new Errors($errorMock);

        // Act
        //
        // Действие
        $result = $errors->getStatus();

        // Assert
        //
        // Проверка
        $this->assertEquals(422, $result);
    }
}
