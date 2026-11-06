<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Integration\Error;

class ErrorTest extends TestCase
{
    public function testToArrayIncludesStatusDetailAndSource(): void
    {
        // Arrange
        //
        // Подготовка
        $status = 404;
        $detail = 'Not Found';
        $source = ['pointer' => '/data/id'];
        $error = new Error($status, $detail, null, $source);

        // Act
        //
        // Действие
        $result = $error->toArray();

        // Assert
        //
        // Проверка
        self::assertEquals([
            'status' => $status,
            'detail' => $detail,
            'source' => $source,
        ], $result);
    }

    public function testToArrayIncludesTitleWhenProvided(): void
    {
        // Arrange
        //
        // Подготовка
        $status = 400;
        $detail = 'Bad Request';
        $title = 'Invalid Input';
        $error = new Error($status, $detail, $title);

        // Act
        //
        // Действие
        $result = $error->toArray();

        // Assert
        //
        // Проверка
        self::assertEquals([
            'status' => $status,
            'detail' => $detail,
            'title' => $title,
            'source' => [],
        ], $result);
    }

    public function testJsonSerializeReturnsArray(): void
    {
        // Arrange
        //
        // Подготовка
        $status = 500;
        $detail = 'Internal Server Error';
        $error = new Error($status, $detail);

        // Act
        //
        // Действие
        $result = $error->jsonSerialize();

        // Assert
        //
        // Проверка
        self::assertEquals([
            'status' => $status,
            'detail' => $detail,
            'source' => [],
        ], $result);
    }
}
