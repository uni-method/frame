<?php

declare(strict_types=1);

namespace UniMethod\Frame\Integration;

use JsonSerializable;

final readonly class Error implements JsonSerializable
{
    /**
     * @param array<string> $source
     */
    public function __construct(
        public int $status,
        public string $detail,
        public ?string $title = null,
        public array $source = [],
    ) {
    }

    /**
     * @return array<string, array<string>|int|string>
     */
    public function toArray(): array
    {
        $result = [
            'status' => $this->status,
            'detail' => $this->detail,
            'source' => $this->source,
        ];

        if ($this->title !== null) {
            $result['title'] = $this->title;
        }

        return $result;
    }

    /**
     * @return array<string, array<string>|int|string>
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
