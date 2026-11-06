<?php

namespace UniMethod\Frame\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Persistence\{
    EntitySaverInterface,
    ListSavers,
    SaverNotConfiguredException,
};

class ListSaversTest extends TestCase
{
    public function testGetEntitySaverByNameReturnsCorrectSaver(): void
    {
        // Arrange
        //
        // Подготовка
        $saverName = "testSaver";
        $mockSaver = $this->createStub(EntitySaverInterface::class);
        $listSavers = new ListSavers([$saverName => $mockSaver]);

        // Act
        //
        // Действие
        $result = $listSavers->getEntitySaverByName($saverName);

        // Assert
        //
        // Проверка
        $this->assertSame($mockSaver, $result);
    }

    public function testGetEntitySaverByNameThrowsWhenNotConfigured(): void
    {
        // Arrange
        //
        // Подготовка
        $saverName = "nonExistentSaver";
        $listSavers = new ListSavers([]);

        // Act & Assert
        //
        // Действие и проверка
        $this->expectException(SaverNotConfiguredException::class);
        $listSavers->getEntitySaverByName($saverName);
    }
}
