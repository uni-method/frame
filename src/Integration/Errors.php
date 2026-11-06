<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

use JsonSerializable;

final class Errors implements JsonSerializable
{
    public const int DEFAULT_ERROR_CODE = 400;

    public const int UNAUTHORIZED_ERROR_CODE = 401;

    public const int FORBIDDEN_ERROR_CODE = 403;

    public const int NOT_FOUND_ERROR_CODE = 404;

    public const int METHOD_NOT_ALLOWED_ERROR_CODE = 405;

    public const int CONFLICT_ERROR_CODE = 409;

    public const int ENTITY_VALIDATION_ERROR_CODE = 422;

    /**
     * @var array<Error>
     */
    public array $errors = [];

    public function __construct(Error ...$errors)
    {
        foreach ($errors as $error) {
            $this->errors[] = $error;
        }
    }

    public function getStatus(): int
    {
        if ($this->errors === []) {
            return self::DEFAULT_ERROR_CODE;
        }

        $status = $this->errors[0]->status;

        foreach ($this->errors as $error) {
            if ($error->status !== $status) {
                return self::DEFAULT_ERROR_CODE;
            }
        }

        return $status;
    }

    /**
     * @return array<"errors", array<array<string, array<string>|int|string>>>
     */
    public function toArray(): array
    {
        return [
            "errors" => array_map(
                static fn (Error $error): array => $error->toArray(),
                $this->errors,
            ),
        ];
    }

    /**
     * @return array<"errors", array<array<string, array<string>|int|string>>>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
