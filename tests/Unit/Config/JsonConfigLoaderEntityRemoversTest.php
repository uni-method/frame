<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\{Action, JsonConfigLoader};
use UniMethod\Frame\Tests\Unit\Config\Stub\{TestEntityLocator, TestEntityRemover, TestEntitySaver};
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderEntityRemoversTest extends TestCase
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

    private function schemaOrder(): string
    {
        return '"order": { "attributes": { "id": { "type": "int" } } }';
    }

    private function implUser(): string
    {
        return '"user": { "class": "App\\\\User", "attributes": { "id": { "getter": "getId" } } }';
    }

    private function implProduct(): string
    {
        return '"product": { "class": "App\\\\Product", "attributes": { "id": { "getter": "getId" } } }';
    }

    private function implOrder(): string
    {
        return '"order": { "class": "App\\\\Order", "attributes": { "id": { "getter": "getId" } } }';
    }

    // ── tests ─────────────────────────────────────────────────────────────────
    //
    // ── тесты ─────────────────────────────────────────────────────────────────

    public function testLoadModelWithEntityRemover(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "doctrine" } } }, "removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->models);
        $this->assertCount(1, $config->removers);

        $action = $config->actions[0];
        $this->assertSame("doctrine", $action->entityRemover);

        $this->assertArrayHasKey("doctrine", $config->removers);
        $this->assertSame(
            TestEntityRemover::class,
            $config->removers["doctrine"],
        );
    }

    public function testLoadModelWithLocatorSaverAndRemover(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "read": {}, "create": {}, "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "read": { "entityLocator": "doctrine" }, "create": { "entitySaver": ' .
                '"doctrine" }, "delete": { "entityLocator": "doctrine", "entityRemover": "doctrine" } } }, ' .
                '"locators": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityLocator" }, "savers": { ' .
                '"doctrine": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" }, ' .
                '"removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->models);
        $this->assertCount(1, $config->locators);
        $this->assertCount(1, $config->savers);
        $this->assertCount(1, $config->removers);

        /** @var array<string, Action> $actionsByType */
        $actionsByType = [];
        foreach ($config->actions as $action) {
            $actionsByType[$action->type->value] = $action;
        }

        $this->assertSame("doctrine", $actionsByType["read"]->entityLocator);
        $this->assertSame("doctrine", $actionsByType["create"]->entitySaver);
        $this->assertSame("doctrine", $actionsByType["delete"]->entityRemover);

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

        $this->assertArrayHasKey("doctrine", $config->removers);
        $this->assertSame(
            TestEntityRemover::class,
            $config->removers["doctrine"],
        );
    }

    public function testLoadModelWithOnlyEntityRemover(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "custom" } } }, "removers": { "custom": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $config = $this->loader->loadFromString($json);

        $action = $config->actions[0];
        $this->assertNull($action->entityLocator);
        $this->assertNull($action->entitySaver);
        $this->assertSame("custom", $action->entityRemover);
    }

    public function testLoadModelWithoutEntityRemoverIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' . $this->schemaUser() . " } }",
            '{ "models": { "user": { "class": "App\\\\UserDTO", "attributes": { "id": { "getter": "getId" } } } } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(1, $config->models);
        $this->assertEmpty($config->actions);
        $this->assertEmpty($config->removers);
    }

    public function testLoadMultipleModelsWithDifferentRemovers(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ", " .
                $this->schemaProduct() .
                ' }, "actions": { "user": { "delete": {} }, "product": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ", " .
                $this->implProduct() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "doctrine" } }, "product": { "delete": { ' .
                '"entityRemover": "elasticsearch" } } }, "removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover", "elasticsearch": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(2, $config->models);
        $this->assertCount(2, $config->removers);

        $this->assertSame("doctrine", $config->actions[0]->entityRemover);
        $this->assertSame("elasticsearch", $config->actions[1]->entityRemover);
    }

    public function testUndefinedEntityRemoverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "undefined-remover" } } }, "removers": { ' .
                '"doctrine": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Action 'delete' on model 'user' references undefined remover 'undefined-remover'",
        );

        $this->loader->loadFromString($json);
    }

    public function testEmptyEntityRemoverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "" } } }, "removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid 'entityRemover' for action 'delete' on model 'user': must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testWhitespaceEntityRemoverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "   " } } }, "removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Invalid 'entityRemover' for action 'delete' on model 'user': must be a non-empty string",
        );

        $this->loader->loadFromString($json);
    }

    public function testUnusedRemoverThrows(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "doctrine" } } }, "removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover", "unused": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage(
            "Remover 'unused' is declared but not used by any action",
        );

        $this->loader->loadFromString($json);
    }

    public function testRemoverUsedByMultipleModelsIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ", " .
                $this->schemaProduct() .
                ' }, "actions": { "user": { "delete": {} }, "product": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ", " .
                $this->implProduct() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "doctrine" } }, "product": { "delete": { ' .
                '"entityRemover": "doctrine" } } }, "removers": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertCount(2, $config->models);
        $this->assertCount(1, $config->removers);
        $this->assertSame("doctrine", $config->actions[0]->entityRemover);
        $this->assertSame("doctrine", $config->actions[1]->entityRemover);
    }

    public function testEmptyRemoversArrayIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' . $this->schemaUser() . " } }",
            '{ "models": { "user": { "class": "App\\\\UserDTO", "attributes": { "id": { "getter": "getId" } } } }, ' .
            '"removers": {} }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertEmpty($config->removers);
    }

    public function testRemoversNotSpecifiedIsValid(): void
    {
        $json = $this->wrap(
            '{ "models": { ' . $this->schemaUser() . " } }",
            '{ "models": { "user": { "class": "App\\\\UserDTO", "attributes": { "id": { "getter": "getId" } } } } }',
        );

        $config = $this->loader->loadFromString($json);

        $this->assertEmpty($config->removers);
    }

    public function testModelWithDifferentLocatorSaverAndRemover(): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "read": {}, "create": {}, "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "read": { "entityLocator": "doctrine" }, "create": { "entitySaver": ' .
                '"redis" }, "delete": { "entityLocator": "doctrine", "entityRemover": "elasticsearch" } } }, ' .
                '"locators": { "doctrine": ' .
                '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityLocator" }, "savers": { ' .
                '"redis": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntitySaver" }, "removers": ' .
                '{ "elasticsearch": "UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }',
        );

        $config = $this->loader->loadFromString($json);

        /** @var array<string, Action> $actionsByType */
        $actionsByType = [];
        foreach ($config->actions as $action) {
            $actionsByType[$action->type->value] = $action;
        }

        $this->assertSame("doctrine", $actionsByType["read"]->entityLocator);
        $this->assertSame("redis", $actionsByType["create"]->entitySaver);
        $this->assertSame(
            "elasticsearch",
            $actionsByType["delete"]->entityRemover,
        );

        $this->assertCount(1, $config->locators);
        $this->assertCount(1, $config->savers);
        $this->assertCount(1, $config->removers);
    }

    public function testMultipleModelsWithMixedRemoverUsage(): void
    {
        $schema =
            '{ "models": { ' .
            $this->schemaUser() .
            ", " .
            $this->schemaProduct() .
            ", " .
            $this->schemaOrder() .
            ' }, "actions": { "user": { "delete": {} }, "order": { "delete": {} } } }';
        $impl =
            '{ "models": { ' .
            $this->implUser() .
            ", " .
            $this->implProduct() .
            ", " .
            $this->implOrder() .
            ' }, "actions": { "user": { "delete": { "entityRemover": "doctrine" } }, "order": { "delete": { ' .
            '"entityRemover": "custom" } } }, "removers": { "doctrine": ' .
            '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover", "custom": ' .
            '"UniMethod\\\\Frame\\\\Tests\\\\Unit\\\\Config\\\\Stub\\\\TestEntityRemover" } }';

        $config = $this->loader->loadFromString($this->wrap($schema, $impl));

        $this->assertCount(3, $config->models);
        $this->assertCount(2, $config->removers);

        $actionsByModel = [];
        foreach ($config->actions as $action) {
            $actionsByModel[$action->model] = $action;
        }

        $this->assertSame("doctrine", $actionsByModel["user"]->entityRemover);
        $this->assertArrayNotHasKey("product", $actionsByModel);
        $this->assertSame("custom", $actionsByModel["order"]->entityRemover);
    }

    /**
     * The `impl.removers` value is an existing class implementing EntityRemoverInterface.
     *
     * Значение `impl.removers` — существующий класс, реализующий EntityRemoverInterface.
     */
    #[DataProvider("provideInvalidRemoverClasses")]
    public function testInvalidRemoverClassThrows(string $class, string $message): void
    {
        $json = $this->wrap(
            '{ "models": { ' .
                $this->schemaUser() .
                ' }, "actions": { "user": { "delete": {} } } }',
            '{ "models": { ' .
                $this->implUser() .
                ' }, "actions": { "user": { "delete": { "entityRemover": "x" } } }, "removers": { "x": ' .
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
    public static function provideInvalidRemoverClasses(): iterable
    {
        yield "класс не существует" => [
            "App\\Does\\Not\\Exist",
            "Remover 'x' refers to undefined class 'App\\Does\\Not\\Exist'",
        ];
        yield "класс без EntityRemoverInterface" => [
            stdClass::class,
            "Remover 'x' class 'stdClass' must implement EntityRemoverInterface",
        ];
    }
}
