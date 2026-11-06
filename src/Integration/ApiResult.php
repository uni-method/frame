<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

/**
 * @template-covariant TData of array<object>|object|string
 */
final class ApiResult
{
    /**
     * @param TData $data
     * @param array<string, mixed> $headers
     * @param bool $encoded data is already serialized (a JSON string)
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array|object|string $data,
        public readonly array $headers = [],
        public readonly bool $encoded = true,
    ) {
    }

    /** @return self<Errors> */
    public static function error(Error $error): self
    {
        return new self($error->status, new Errors($error), [], false);
    }

    /** @return self<Errors> */
    public static function errors(Error ...$error): self
    {
        $errors = new Errors(...$error);
        return new self($errors->getStatus(), $errors, [], false);
    }
}
