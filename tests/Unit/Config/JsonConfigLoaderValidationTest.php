<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Config\JsonConfigLoader;
use UniMethod\Frame\Tests\Unit\Stub\{TrimMutator, User};
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

/**
 * Structural validation of config fields at load: a value of the wrong type —
 * InvalidConfigStructureException, not a TypeError from the depths of the loader.
 *
 * Структурная валидация полей конфига при загрузке: значение неверного типа —
 * InvalidConfigStructureException, а не TypeError из глубины загрузчика.
 */
final class JsonConfigLoaderValidationTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    /**
     * `schema.actions.<model>.<action>.writable` — an object or a list.
     *
     * `schema.actions.<model>.<action>.writable` — объект или список.
     */
    public function testWritableMustBeArrayOrNull(): void
    {
        $config = $this->baseConfig();
        $config["schema"]["actions"] = [
            "user" => ["create" => ["writable" => "author"]],
        ];
        $config["impl"]["actions"] = [
            "user" => ["create" => ["entitySaver" => "saver"]],
        ];
        $config["impl"]["savers"] = ["saver" => "App\\Saver"];

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessageMatches("/writable/");

        $this->loader->loadFromString($this->json($config));
    }

    /**
     * `mutators.<phase>` — a list of non-empty alias strings.
     *
     * `mutators.<phase>` — список непустых строк-алиасов.
     */
    #[DataProvider("provideInvalidMutatorPhases")]
    public function testMutatorPhaseMustBeListOfAliases(mixed $phase): void
    {
        $config = $this->baseConfig();
        $config["schema"]["models"]["user"]["attributes"]["name"]["mutators"] = [
            "afterInput" => $phase,
        ];
        $config["impl"]["models"]["user"]["attributes"]["name"]["setter"] = "setName";
        $config["impl"]["mutators"] = ["trim" => TrimMutator::class];

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessageMatches("/mutators\\.afterInput/");

        $this->loader->loadFromString($this->json($config));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidMutatorPhases(): iterable
    {
        yield "строка вместо списка" => ["trim"];
        yield "элемент списка не строка" => [[["trim"]]];
    }

    /**
     * Dictionaries `impl.locators/savers/removers/mutators/relationMutators` —
     * an object "alias string → class name".
     *
     * Словари `impl.locators/savers/removers/mutators/relationMutators` —
     * объект «алиас-строка → имя класса».
     *
     * @param array<string, mixed> $patch
     */
    #[DataProvider("provideInvalidImplDictionaries")]
    public function testImplDictionaryMustMapAliasesToClassNames(array $patch): void
    {
        $config = $this->baseConfig();
        $config["schema"]["actions"] = ["user" => ["read" => []]];
        $config["impl"] = array_replace($config["impl"], $patch);

        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessageMatches("/locators/");

        $this->loader->loadFromString($this->json($config));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidImplDictionaries(): iterable
    {
        yield "строка вместо объекта" => [[
            "actions" => ["user" => ["read" => ["entityLocator" => "loc"]]],
            "locators" => "App\\Locator",
        ]];
        yield "числовой ключ" => [[
            "actions" => ["user" => ["read" => ["entityLocator" => "1"]]],
            "locators" => ["1" => "App\\Locator"],
        ]];
        yield "значение не строка" => [[
            "actions" => ["user" => ["read" => ["entityLocator" => "loc"]]],
            "locators" => ["loc" => ["App\\Locator"]],
        ]];
    }

    /**
     * Minimal valid config: a user model (id:int, name:string) with impl.
     *
     * Минимальный валидный конфиг: модель user (id:int, name:string) с impl.
     *
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        return [
            "schema" => [
                "models" => [
                    "user" => [
                        "attributes" => [
                            "id" => ["type" => "int"],
                            "name" => ["type" => "string"],
                        ],
                    ],
                ],
                "actions" => new stdClass(),
            ],
            "impl" => [
                "models" => [
                    "user" => [
                        "class" => User::class,
                        "attributes" => [
                            "id" => ["getter" => "getId"],
                            "name" => ["getter" => "getName"],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function json(array $config): string
    {
        return json_encode(
            $config,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
