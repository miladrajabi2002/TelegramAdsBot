<?php

namespace App\Services\Payments\Data;

final readonly class ZarinPayVerifyResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public bool $successful,
        public ?int $code,
        public int|string|null $paymentId,
        public ?int $amountIrr,
        public ?string $merchantReference,
        public ?string $authority,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    public function provesPayment(): bool
    {
        return $this->successful && in_array($this->code, [100, 101], true);
    }
}
