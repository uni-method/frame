<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Config\{Config, JsonConfigLoader};
use UniMethod\Frame\Config\Exception\InvalidJsonException;
use UniMethod\Frame\Validator\Exception\InvalidConfigStructureException;

final class JsonConfigLoaderImplementationTest extends TestCase
{
    private JsonConfigLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new JsonConfigLoader();
    }

    /* -----------------------------------------------------------------
     * load()
     * ----------------------------------------------------------------- */

    public function testLoadWithUnreadableFileThrowsInvalidJsonException(): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage("Unable to read file");

        @$this->loader->load("/path/to/not-existing-file.json");
    }

    public function testLoadValidFileIsDelegatedToLoadFromString(): void
    {
        $file = tempnam(sys_get_temp_dir(), "json_loader_");
        file_put_contents($file, '{"schema":{},"impl":{}}');

        try {
            $config = $this->loader->load($file);
            $this->assertInstanceOf(Config::class, $config);
        } finally {
            unlink($file);
        }
    }

    /* -----------------------------------------------------------------
     * loadFromString() – JSON parsing
     *
     * loadFromString() – разбор JSON
     * ----------------------------------------------------------------- */

    public function testLoadFromStringWithInvalidJsonThrowsInvalidJsonException(): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage("Failed to parse JSON");

        $this->loader->loadFromString("{ invalid json ");
    }

    public function testLoadFromStringWithScalarRootThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("Root element must be an object");

        $this->loader->loadFromString('"string"');
    }

    public function testLoadFromStringWithNullRootThrowsException(): void
    {
        $this->expectException(InvalidConfigStructureException::class);
        $this->expectExceptionMessage("Root element must be an object");

        $this->loader->loadFromString("null");
    }

    /* -----------------------------------------------------------------
     * loadFromString() – happy path
     *
     * loadFromString() – успешный сценарий
     * ----------------------------------------------------------------- */

    public function testLoadFromStringWithEmptyObjectIsValid(): void
    {
        $config = $this->loader->loadFromString('{"schema":{},"impl":{}}');

        $this->assertInstanceOf(Config::class, $config);
    }

    public function testLoadFromStringWithMinimalValidConfig(): void
    {
        $json = <<<'JSON'
        {
          "schema": {
            "models": {
              "user": { "attributes": { "id": { "type": "int" } } }
            }
          },
          "impl": {
            "models": {
              "user": {
                "class": "App\\User",
                "attributes": { "id": { "getter": "getId" } }
              }
            }
          }
        }
        JSON;

        $config = $this->loader->loadFromString($json);

        $this->assertInstanceOf(Config::class, $config);
        $this->assertCount(1, $config->models);
    }

    public function testLoadFromStringThrowsInvalidJsonExceptionFromJsonException(): void
    {
        $this->expectException(InvalidJsonException::class);
        $this->expectExceptionMessage(
            "Failed to parse JSON: Malformed UTF-8 characters, possibly incorrectly encoded",
        );

        $invalidUtf8 = "\xB1\x31";
        $this->loader->loadFromString($invalidUtf8);
    }
}
