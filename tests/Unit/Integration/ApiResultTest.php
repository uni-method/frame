<?php

declare(strict_types=1);

namespace UniMethod\Frame\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use UniMethod\Frame\Integration\{ApiResult, Error, Errors};

final class ApiResultTest extends TestCase
{
    public function testEncodedDefaultsToTrue(): void
    {
        $result = new ApiResult(200, '{"id":"1"}');

        $this->assertTrue($result->encoded);
    }

    public function testEncodedIsStoredFromConstructor(): void
    {
        $result = new ApiResult(200, [], [], false);

        $this->assertFalse($result->encoded);
    }

    public function testErrorFactoriesAreNotEncoded(): void
    {
        $error = new Error(Errors::NOT_FOUND_ERROR_CODE, "Not found.");

        $this->assertFalse(ApiResult::error($error)->encoded);
        $this->assertFalse(ApiResult::errors($error)->encoded);
    }
}
