<?php

namespace App\Services\Payments\Data;

final readonly class ZarinPayCreateResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public bool $successful,
        public ?string $paymentLink,
        public ?string $authority,
        public ?string $message = null,
        public array $raw = [],
    ) {}
}
