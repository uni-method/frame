<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{Action, JsonConfigLoader};
use UniMethod\Frame\Tests\Unit\Config\Stub\{TestEntityLocator, TestEntitySaver};
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderEntitySaversTest extends TestCase
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

    private function schemaProduct(): string
    {
        return '"product": { "attributes": { "id": { "type": "int" } } }';
    }

    private function implUser(string $class = "App\\\\User"): string
    {
        return '"user": { "class": "' .
            $class .
            '", "attributes": { "id": { "getter": "getId" } } }';
    }

    private function implProduct(): string
    {
        return '"product": { "class": "App\\\\Product", "attributes": { "id": { "getter": "getId" } } }';
    }

    // ── tests ─────────────────────────────────────────────────────────────────
    //
    // ── тесты ─────────────────────────────────────────────────────────────────

    public function testLoadModelWithEntityLocatorAndEntitySaver(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "read": {}, "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "read": { "entityLocator": "doctrine" }, "create": { "entitySaver": ' .
                '"doctrine" } } }, "locators": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityLocator" }, "savers": { ' .
                '"doctrine": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->models);
        $this->assertCount(1, $config->locators);
        $this->assertCount(1, $config->savers);

        /** @var array<string, Action> $actionsByType */
        $actionsByType = [];
        foreach ($config->actions as $action) {
            $actionsByType[$action->type->value] = $action;
        }

        $this->assertSame("doctrine", $actionsByType["read"]->entityLocator);
        $this->assertSame("doctrine", $actionsByType["create"]->entitySaver);

        $this->assertArrayHasKey("doctrine", $config->locators);
        $this->assertSame(
            TestEntityLocator::class,
            $config->locators["doctrine"],
        );

        $this->assertArrayHasKey("doctrine", $config->savers);
        $this->assertSame(
            TestEntitySaver::class,
            $config->savers["doctrine"],
        );
    }

    public function testLoadModelWithOnlyEntitySaver(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "create": { "entitySaver": "custom" } } }, "savers": { "custom": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertNull($action->entityLocator);
        $this->assertSame("custom", $action->entitySaver);
    }

    public function testLoadModelWithoutEntitySaverIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' . $this->schemaUser() . " } }",
            '{ "models": { ' . $this->implUser("App\\\\UserDTO") . " } }",
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->models);
        $this->assertEmpty($config->actions);
        $this->assertEmpty($config->savers);
    }

    public function testLoadMultipleModelsWithDifferentSavers(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ", " .
                $this->schemaProduct() .
                ' }, "actions": { "user": { "create": {} }, "product": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ", " .
                $this->implProduct() .
                ' }, "actions": { "user": { "create": { "entitySaver": "doctrine" } }, "product": { "create": { ' .
                '"entitySaver": "elasticsearch" } } }, "savers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver", "elasticsearch": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(2, $config->models);
        $this->assertCount(2, $config->savers);

        $this->assertSame("doctrine", $config->actions[0]->entitySaver);
        $this->assertSame("elasticsearch", $config->actions[1]->entitySaver);
    }

    public function testUndefinedEntitySaverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "create": { "entitySaver": "undefined-saver" } } }, "savers": { ' .
                '"doctrine": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Action 'create' on model 'user' references undefined saver 'undefined-saver'",
        );

        $this->loader->loadFromString($json);
    }

    public function testEmptyEntitySaverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "create": { "entitySaver": "" } } }, "savers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid 'entitySaver' for action 'create' on model 'user': must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testWhitespaceEntitySaverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "create": { "entitySaver": "   " } } }, "savers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid 'entitySaver' for action 'create' on model 'user': must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testUnusedSaverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "create": { "entitySaver": "doctrine" } } }, "savers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver", "unused": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Saver 'unused' is declared but not used by any action",
        );

        $this->loader->loadFromString($json);
    }

    public function testSaverUsedByMultipleModelsIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ", " .
                $this->schemaProduct() .
                ' }, "actions": { "user": { "create": {} }, "product": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ", " .
                $this->implProduct() .
                ' }, "actions": { "user": { "create": { "entitySaver": "doctrine" } }, "product": { "create": { ' .
                '"entitySaver": "doctrine" } } }, "savers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(2, $config->models);
        $this->assertCount(1, $config->savers);
        $this->assertSame("doctrine", $config->actions[0]->entitySaver);
        $this->assertSame("doctrine", $config->actions[1]->entitySaver);
    }

    public function testEmptySaversArrayIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' . $this->schemaUser() . " } }",
            '{ "models": { ' .
                $this->implUser("App\\\\UserDTO") .
                ' }, "savers": {} }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertEmpty($config->savers);
    }

    public function testSaversNotSpecifiedIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' . $this->schemaUser() . " } }",
            '{ "models": { ' . $this->implUser("App\\\\UserDTO") . " } }",
        );

        $config = $this->loader->loadFromString($json);

        $this->assertEmpty($config->savers);
    }

    /**
     * The `impl.savers` value is an existing class implementing EntitySaverInterface.
     *
     * Значение `impl.savers` — существующий класс, реализующий EntitySaverInterface.
     */
    #[DataProvider("provideInvalidSaverClasses")]
    public function testInvalidSaverClassThrows(string $class, string $message): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "create": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "create": { "entitySaver": "x" } } }, "savers": { "x": ' .
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
    public static function provideInvalidSaverClasses(): iterable
    {
        yield "класс не существует" => [
            "App\\Does\\Not\\Exist",
            "Saver 'x' refers to undefined class 'App\\Does\\Not\\Exist'",
        ];
        yield "класс без EntitySaverInterface" => [
            stdClass::class,
            "Saver 'x' class 'stdClass' must implement EntitySaverInterface",
        ];
    }
}
