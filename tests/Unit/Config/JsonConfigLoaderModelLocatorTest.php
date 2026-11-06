<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Tests\Unit\Config\Stub\TestEntityLocator;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * Model locator (`impl.models.<model>.entityLocator`) and the default
 * locator (`impl.defaultLocator`): what the model is loaded by id with, without
 * a read action.
 *
 * Локатор модели (`impl.models.<модель>.entityLocator`) и локатор по
 * умолчанию (`impl.defaultLocator`): чем модель грузится по id, без
 * read-действия.
 */
final class JsonConfigLoaderModelLocatorTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    /**
     * The model locator gets into Model and is considered used: there is no other
     * reference to the alias in the config.
     *
     * Локатор модели попадает в Model и считается использованным: другой
     * ссылки на алиас в конфиге нет.
     */
    public function testModelLocatorIsLoaded(): void
    {
        $config = $this->loader->loadFromString($this->json(
            userImpl: ["entityLocator" => "doctrine"],
        ));

        self::assertSame("doctrine", $config->models[1]->entityLocator);
        self::assertSame("doctrine", $config->locatorForModel("user"));
        self::assertNull($config->locatorForModel("post"));
    }

    public function testDefaultLocatorIsLoaded(): void
    {
        $config = $this->loader->loadFromString($this->json(impl: ["defaultLocator" => "doctrine"]));

        self::assertSame("doctrine", $config->defaultLocator);
        self::assertSame("doctrine", $config->locatorForModel("user"));
        self::assertSame("doctrine", $config->locatorForModel("post"));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function provideInvalidLocators(): iterable
    {
        yield "модель: неизвестный алиас" => [
            ["entityLocator" => "nope"],
            [],
            "Field 'impl.models[user].entityLocator' references undefined locator 'nope'",
        ];
        yield "модель: пустая строка" => [
            ["entityLocator" => ""],
            [],
            "Field 'impl.models[user].entityLocator' must be a non-empty string",
        ];
        yield "модель: не строка" => [
            ["entityLocator" => 5],
            [],
            "Field 'impl.models[user].entityLocator' must be a non-empty string",
        ];
        yield "по умолчанию: неизвестный алиас" => [
            [],
            ["defaultLocator" => "nope"],
            "Field 'impl.defaultLocator' references undefined locator 'nope'",
        ];
        yield "по умолчанию: не строка" => [
            [],
            ["defaultLocator" => ["doctrine"]],
            "Field 'impl.defaultLocator' must be a non-empty string",
        ];
    }

    /**
     * @param array<string, mixed> $userImpl
     * @param array<string, mixed> $impl
     */
    #[DataProvider("provideInvalidLocators")]
    public function testInvalidLocatorIsRejected(array $userImpl, array $impl, string $message): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage($message);

        $this->loader->loadFromString($this->json($userImpl, $impl));
    }

    /**
     * post and user (relation post.author → user), the `doctrine` locator is declared,
     * there are no actions with a locator.
     *
     * post и user (связь post.author → user), локатор `doctrine` объявлен,
     * действий с локатором нет.
     *
     * @param array<string, mixed> $userImpl
     * @param array<string, mixed> $impl
     */
    private function json(array $userImpl = [], array $impl = []): string
    {
        $config = [
            "schema" => [
                "models" => [
                    "post" => [
                        "attributes" => ["id" => ["type" => "int"]],
                        "relations" => ["author" => ["type" => "manyToOne", "targetModel" => "user"]],
                    ],
                    "user" => ["attributes" => ["id" => ["type" => "int"]]],
                ],
            ],
            "impl" => [
                "models" => [
                    "post" => [
                        "class" => "App\\Post",
                        "attributes" => ["id" => ["getter" => "getId"]],
                        "relations" => ["author" => ["getter" => "getAuthor"]],
                    ],
                    "user" => ["class" => "App\\User", "attributes" => ["id" => ["getter" => "getId"]]] + $userImpl,
                ],
                "locators" => ["doctrine" => TestEntityLocator::class],
            ] + $impl,
        ];

        return json_encode($config, JSON_THROW_ON_ERROR);
    }
}
