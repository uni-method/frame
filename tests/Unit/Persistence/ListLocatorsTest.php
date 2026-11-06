<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Persistence\{
    EntityLocatorInterface,
    ListLocators,
    LocatorNotConfiguredException,
};

class ListLocatorsTest extends TestCase
{
    public function testGetEntityLocatorByNameThrowsExceptionWhenLocatorNotFound(): void
    {
        // Prepare data for the test
        //
        // Подготавливаем данные для теста
        // Empty array of locators
        //
        // Пустой массив локаторов
        $locators = [];
        // Create an instance of the ListLocators class with an empty array of locators
        //
        // Создаем экземпляр класса ListLocators с пустым массивом локаторов
        $listLocators = new ListLocators($locators);
        // Expect a LocatorNotConfiguredException when calling the getEntityLocatorByName method
        //
        // Ожидаем исключение LocatorNotConfiguredException при вызове метода getEntityLocatorByName
        $this->expectException(LocatorNotConfiguredException::class);
        $this->expectExceptionMessage(
            "Entity locator is not configured for model 'unknown_locator'.",
        );
        // Call the method that must throw the exception
        //
        // Вызываем метод, который должен выбросить исключение
        $listLocators->getEntityLocatorByName("unknown_locator");
    }

    public function testGetEntityLocatorByNameReturnsCorrectLocatorWhenFound(): void
    {
        // Prepare data for the test
        //
        // Подготавливаем данные для теста
        $mockLocator = $this->createStub(EntityLocatorInterface::class);
        $locators = ["test_locator" => $mockLocator];
        // Create an instance of the ListLocators class with an array of locators
        //
        // Создаем экземпляр класса ListLocators с массивом локаторов
        $listLocators = new ListLocators($locators);
        // Call the method and check that the correct locator is returned
        //
        // Вызываем метод и проверяем, что возвращается правильный локатор
        $this->assertSame(
            $mockLocator,
            $listLocators->getEntityLocatorByName("test_locator"),
        );
    }
}
