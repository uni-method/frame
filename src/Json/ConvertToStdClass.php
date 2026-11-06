<?php

declare(strict_types=1);

namespace UniMethod\Frame\Json;

use JsonException;

class ConvertToStdClass
{
    public function convert(string $json): object
    {
        try {
            $result = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            if (is_object($result)) {
                return $result;
            }

            throw new InvalidJsonStructureException(
                'JSON must contain an object, ' . gettype($result) . ' given'
            );
        } catch (JsonException $jsonException) {
            throw new JsonDecodeException(
                'Failed to decode JSON: ' . $jsonException->getMessage(),
                $jsonException->getCode(),
                previous: $jsonException
            );
        }
    }
}
