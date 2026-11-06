<?php

namespace UniMethod\Frame\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Persistence\{
    EntityRemoverInterface,
    ListRemovers,
    RemoverNotConfiguredException,
};

class ListRemoversTest extends TestCase
{
    public function testGetEntityRemoverByNameReturnsRemoverWhenConfigured(): void
    {
        // Arrange
        //
        // Подготовка
        $name = "testRemover";
        $removerMock = $this->createStub(EntityRemoverInterface::class);
        $removers = [$name => $removerMock];
        $listRemovers = new ListRemovers($removers);

        // Act
        //
        // Действие
        $result = $listRemovers->getEntityRemoverByName($name);

        // Assert
        //
        // Проверка
        $this->assertSame($removerMock, $result);
    }

    public function testGetEntityRemoverByNameThrowsWhenNotConfigured(): void
    {
        // Arrange
        //
        // Подготовка
        $name = "testRemover";
        $removers = [];
        $listRemovers = new ListRemovers($removers);

        // Act & Assert
        //
        // Действие и проверка
        $this->expectException(RemoverNotConfiguredException::class);
        $this->expectExceptionMessage($name);
        $listRemovers->getEntityRemoverByName($name);
    }
}
