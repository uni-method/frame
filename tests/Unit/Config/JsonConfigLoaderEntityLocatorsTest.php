<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderEntityLocatorsTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    // ── helpers ───────────────────────────────────────────────────────────────
    //
    // ── хелперы ───────────────────────────────────────────────────────────────

    private function wrap(string $schemaBody, string $implBody): string
    {
        return <<<JSON
        { "schema": {$schemaBody}, "impl": {$implBody} }
        JSON;
    }

    private function schemaUser(): string
    {
        return '"user": { "attributes": { "id": { "type": "int" } } }';
    }

    private function implUser(): string
    {
        return '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }';
    }

    // ── tests ─────────────────────────────────────────────────────────────────
    //
    // ── тесты ─────────────────────────────────────────────────────────────────

    /**
     * The `impl.locators` value is an existing class implementing EntityLocatorInterface.
     *
     * Значение `impl.locators` — существующий класс, реализующий EntityLocatorInterface.
     */
    #[DataProvider("provideInvalidLocatorClasses")]
    public function testInvalidLocatorClassThrows(string $class, string $message): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "read": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "read": { "entityLocator": "x" } } }, "locators": { "x": ' .
                json_encode($class, JSON_THROW_ON_ERROR) .
                ' } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage($message);

        $this->loader->loadFromString($json);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidLocatorClasses(): iterable
    {
        yield "класс не существует" => [
            "App\\Does\\Not\\Exist",
            "Locator 'x' refers to undefined class 'App\\Does\\Not\\Exist'",
        ];
        yield "класс без EntityLocatorInterface" => [
            stdClass::class,
            "Locator 'x' class 'stdClass' must implement EntityLocatorInterface",
        ];
    }
}
