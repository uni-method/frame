<?php

declare(strict_types=1);

namespace UniMethod\Frame\Json;

use JsonException;

class ConvertToJson
{
    public function convert(object $object): string
    {
        try {
            return json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $jsonException) {
            throw new JsonEncodeException(
                'Failed to encode object to JSON: ' . $jsonException->getMessage(),
                $jsonException->getCode(),
                previous: $jsonException
            );
        }
    }
}
