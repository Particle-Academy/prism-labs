<?php

declare(strict_types=1);

namespace App\Lab;

readonly class PrismTestCase
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $model,
        public string $feature,
        public string $label,
        public bool $costly = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
