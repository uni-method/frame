<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Json;

use PHPUnit\Framework\TestCase;
use stdClass;
use UniMethod\Frame\Json\{
    ConvertToStdClass,
    InvalidJsonStructureException,
    JsonDecodeException,
};

final class ConvertToStdClassTest extends TestCase
{
    private ConvertToStdClass $converter;

    protected function setUp(): void
    {
        $this->converter = new ConvertToStdClass();
    }

    public function testConvertValidJsonObject(): void
    {
        $json = '{"name":"John Doe","age":30,"active":true}';

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame("John Doe", $result->name);
        $this->assertSame(30, $result->age);
        $this->assertTrue($result->active);
    }

    public function testConvertEmptyJsonObject(): void
    {
        $json = "{}";

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertEmpty(get_object_vars($result));
    }

    public function testConvertNestedJsonObject(): void
    {
        $json = '{"user":{"name":"Jane","profile":{"age":25}}}';

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertInstanceOf(stdClass::class, $result->user);
        $this->assertInstanceOf(stdClass::class, $result->user->profile);
        $this->assertSame("Jane", $result->user->name);
        $this->assertSame(25, $result->user->profile->age);
    }

    public function testConvertJsonWithNullValues(): void
    {
        $json = '{"name":"Test","value":null}';

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame("Test", $result->name);
        $this->assertNull($result->value);
    }

    public function testConvertJsonWithSpecialCharacters(): void
    {
        $json = '{"text":"Hello \"World\"","emoji":"🚀"}';

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame('Hello "World"', $result->text);
        $this->assertSame("🚀", $result->emoji);
    }

    public function testThrowsJsonDecodeExceptionForInvalidJson(): void
    {
        $json = '{"name": invalid}';

        $this->expectException(JsonDecodeException::class);
        $this->expectExceptionMessage("Failed to decode JSON:");

        $this->converter->convert($json);
    }

    public function testThrowsJsonDecodeExceptionForMalformedJson(): void
    {
        // Unquoted keys
        //
        // Ключи без кавычек
        $json = '{name: "value"}';

        $this->expectException(JsonDecodeException::class);
        $this->expectExceptionMessage("Failed to decode JSON:");

        $this->converter->convert($json);
    }

    public function testThrowsJsonDecodeExceptionForUnclosedBraces(): void
    {
        $json = '{"name":"John"';

        $this->expectException(JsonDecodeException::class);
        $this->expectExceptionMessage("Failed to decode JSON:");

        $this->converter->convert($json);
    }

    public function testThrowsInvalidJsonStructureExceptionForArray(): void
    {
        $json = '["item1","item2","item3"]';

        $this->expectException(InvalidJsonStructureException::class);
        $this->expectExceptionMessage(
            "JSON must contain an object, array given",
        );

        $this->converter->convert($json);
    }

    public function testThrowsInvalidJsonStructureExceptionForString(): void
    {
        $json = '"just a string"';

        $this->expectException(InvalidJsonStructureException::class);
        $this->expectExceptionMessage(
            "JSON must contain an object, string given",
        );

        $this->converter->convert($json);
    }

    public function testThrowsInvalidJsonStructureExceptionForNumber(): void
    {
        $json = "42";

        $this->expectException(InvalidJsonStructureException::class);
        $this->expectExceptionMessage(
            "JSON must contain an object, integer given",
        );

        $this->converter->convert($json);
    }

    public function testThrowsInvalidJsonStructureExceptionForBoolean(): void
    {
        $json = "true";

        $this->expectException(InvalidJsonStructureException::class);
        $this->expectExceptionMessage(
            "JSON must contain an object, boolean given",
        );

        $this->converter->convert($json);
    }

    public function testThrowsInvalidJsonStructureExceptionForNull(): void
    {
        $json = "null";

        $this->expectException(InvalidJsonStructureException::class);
        $this->expectExceptionMessage(
            "JSON must contain an object, NULL given",
        );

        $this->converter->convert($json);
    }

    public function testThrowsJsonDecodeExceptionForEmptyString(): void
    {
        $json = "";

        $this->expectException(JsonDecodeException::class);
        $this->expectExceptionMessage("Failed to decode JSON:");

        $this->converter->convert($json);
    }

    public function testConvertJsonWithUnicodeEscape(): void
    {
        $json = '{"name":"\\u041f\\u0440\\u0438\\u0432\\u0435\\u0442"}';

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame("Привет", $result->name);
    }

    public function testConvertJsonWithNumericKeys(): void
    {
        $json = '{"0":"zero","1":"one","key":"value"}';

        $result = $this->converter->convert($json);

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame("zero", $result->{'0'});
        $this->assertSame("one", $result->{'1'});
        $this->assertSame("value", $result->key);
    }

    public function testJsonDecodeExceptionPreservesPreviousException(): void
    {
        $json = '{"invalid"}';

        try {
            $this->converter->convert($json);
            $this->fail("Expected JsonDecodeException was not thrown");
        } catch (JsonDecodeException $jsonDecodeException) {
            $this->assertInstanceOf(
                \JsonException::class,
                $jsonDecodeException->getPrevious(),
            );
        }
    }
}
