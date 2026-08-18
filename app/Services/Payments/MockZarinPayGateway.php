<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\ZarinPayGateway;
use App\Services\Payments\Data\ZarinPayCreateResult;
use App\Services\Payments\Data\ZarinPayVerifyResult;
use RuntimeException;

final class MockZarinPayGateway implements ZarinPayGateway
{
    /** @var list<array<string, mixed>> */
    public array $createCalls = [];

    /** @var list<string> */
    public array $verifyCalls = [];

    /** @var list<ZarinPayCreateResult> */
    private array $createResults = [];

    /** @var array<string, list<ZarinPayVerifyResult>> */
    private array $verifyResults = [];

    public function queueCreateResult(ZarinPayCreateResult $result): self
    {
        $this->createResults[] = $result;

        return $this;
    }

    public function queueVerifyResult(string $authority, ZarinPayVerifyResult $result): self
    {
        $this->verifyResults[$authority][] = $result;

        return $this;
    }

    public function createPayment(
        int $amountIrr,
        string $merchantReference,
        string $callbackUrl,
        int|string|null $customerUserId = null,
        ?string $description = null,
        ?int $storeId = null,
    ): ZarinPayCreateResult {
        $this->createCalls[] = compact(
            'amountIrr',
            'merchantReference',
            'callbackUrl',
            'customerUserId',
            'description',
            'storeId',
        );

        if ($this->createResults !== []) {
            return array_shift($this->createResults);
        }

        $authority = 'MOCK-'.hash('sha256', $merchantReference);

        return new ZarinPayCreateResult(
            successful: true,
            paymentLink: 'https://mock.zarinpay.test/pay/'.$authority,
            authority: $authority,
            raw: ['success' => true, 'authority' => $authority],
        );
    }

    public function verifyPayment(string $authority): ZarinPayVerifyResult
    {
        $this->verifyCalls[] = $authority;

        if (($this->verifyResults[$authority] ?? []) === []) {
            throw new RuntimeException("No mock verification result queued for authority [{$authority}].");
        }

        return array_shift($this->verifyResults[$authority]);
    }
}
