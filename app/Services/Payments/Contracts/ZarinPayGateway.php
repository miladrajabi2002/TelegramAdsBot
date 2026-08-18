<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\ZarinPayCreateResult;
use App\Services\Payments\Data\ZarinPayVerifyResult;

interface ZarinPayGateway
{
    /**
     * Create a card payment. Amounts are always expressed in IRR minor units.
     */
    public function createPayment(
        int $amountIrr,
        string $merchantReference,
        string $callbackUrl,
        int|string|null $customerUserId = null,
        ?string $description = null,
        ?int $storeId = null,
    ): ZarinPayCreateResult;

    /**
     * Verify an authority server-to-server. A browser callback is never proof
     * of payment by itself.
     */
    public function verifyPayment(string $authority): ZarinPayVerifyResult;
}
