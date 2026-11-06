<?php

declare(strict_types=1);

namespace UniMethod\Frame\Config;

use JsonException;
use UniMethod\Frame\Config\Exception\InvalidJsonException;
use UniMethod\Frame\Validator\Exception\{
    InvalidConfigStructureException,
    MissingRequiredFieldException,
};

final class JsonConfigLoader extends AbstractConfigLoader
{
    /**
     * @throws InvalidJsonException|InvalidConfigStructureException|MissingRequiredFieldException
     */
    public function load(string $filePath): Config
    {
        $data = file_get_contents($filePath);

        if ($data === false) {
            throw new InvalidJsonException(
                sprintf("Unable to read file '%s'", $filePath),
            );
        }

        return $this->loadFromString($data);
    }

    /**
     * @throws InvalidJsonException|InvalidConfigStructureException|MissingRequiredFieldException
     */
    public function loadFromString(string $content): Config
    {
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InvalidJsonException(
                "Failed to parse JSON: " . $jsonException->getMessage(),
                previous: $jsonException,
            );
        }

        if (!is_array($data)) {
            throw new InvalidConfigStructureException(
                "Root element must be an object",
            );
        }

        return $this->createConfig($data);
    }
}
