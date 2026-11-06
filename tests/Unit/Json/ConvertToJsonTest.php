<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Json;

use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Json\{ConvertToJson, JsonEncodeException};

final class ConvertToJsonTest extends TestCase
{
    private ConvertToJson $converter;

    protected function setUp(): void
    {
        $this->converter = new ConvertToJson();
    }

    public function testConvertValidObject(): void
    {
        $object = new stdClass();
        $object->name = "John Doe";
        $object->age = 30;
        $object->active = true;

        $json = $this->converter->convert($object);

        $this->assertJson($json);

        /**
         * @var array{
         *     name: string,
         *     age: int,
         *     active: bool,
         * } $decoded
         */
        $decoded = json_decode($json, true);

        $this->assertSame("John Doe", $decoded["name"]);
        $this->assertSame(30, $decoded["age"]);
        $this->assertTrue($decoded["active"]);
    }

    public function testConvertEmptyObject(): void
    {
        $object = new stdClass();

        $json = $this->converter->convert($object);

        $this->assertSame("{}", $json);
    }

    public function testConvertNestedObject(): void
    {
        $profile = new stdClass();
        $profile->age = 25;

        $user = new stdClass();
        $user->name = "Jane";
        $user->profile = $profile;

        $json = $this->converter->convert($user);

        $this->assertJson($json);
        /**
         * @var array{
         *     name: string,
         *     profile: array{age: int},
         * } $decoded
         */
        $decoded = json_decode($json, true);

        $this->assertSame("Jane", $decoded["name"]);
        $this->assertSame(25, $decoded["profile"]["age"]);
    }

    public function testConvertObjectWithNullValues(): void
    {
        $object = new stdClass();
        $object->name = "Test";
        $object->value = null;

        $json = $this->converter->convert($object);

        $this->assertJson($json);
        /**
         * @var array{
         *     name: string,
         *     value: string|null,
         * } $decoded
         */
        $decoded = json_decode($json, true);

        $this->assertSame("Test", $decoded["name"]);
        $this->assertNull($decoded["value"]);
    }

    public function testConvertObjectWithSpecialCharacters(): void
    {
        $object = new stdClass();
        $object->text = 'Hello "World"';
        $object->emoji = "🚀";

        $json = $this->converter->convert($object);

        $this->assertJson($json);
        /**
         * @var array{
         *     text: string,
         *     emoji: string,
         * } $decoded
         */
        $decoded = json_decode($json, true);

        $this->assertSame('Hello "World"', $decoded["text"]);
        $this->assertSame("🚀", $decoded["emoji"]);
    }

    public function testConvertObjectWithUnicode(): void
    {
        $object = new stdClass();
        $object->name = "Привет";

        $json = $this->converter->convert($object);

        $this->assertStringContainsString("Привет", $json);
        $decoded = json_decode($json, true);

        /**
         * @var array{
         *     name: string
         * } $decoded
         */
        $this->assertSame("Привет", $decoded["name"]);
    }

    public function testThrowsJsonEncodeExceptionForInvalidUtf8(): void
    {
        $object = new stdClass();
        // Invalid UTF-8 sequence
        //
        // Неверная UTF-8 последовательность
        $object->invalid = "\xB1\x31";

        $this->expectException(JsonEncodeException::class);
        $this->expectExceptionMessage("Failed to encode object to JSON:");

        $this->converter->convert($object);
    }

    public function testJsonEncodeExceptionPreservesPreviousException(): void
    {
        $object = new stdClass();
        // json_encode cannot serialize a resource
        //
        // json_encode не умеет сериализовать ресурс
        $object->data = tmpfile();

        try {
            $this->converter->convert($object);
            $this->fail("Expected JsonEncodeException was not thrown");
        } catch (JsonEncodeException $jsonEncodeException) {
            $this->assertInstanceOf(
                \JsonException::class,
                $jsonEncodeException->getPrevious(),
            );
        }
    }
}
