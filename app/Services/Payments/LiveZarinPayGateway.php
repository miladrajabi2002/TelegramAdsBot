<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\ZarinPayGateway;
use App\Services\Payments\Data\ZarinPayCreateResult;
use App\Services\Payments\Data\ZarinPayVerifyResult;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

final class LiveZarinPayGateway implements ZarinPayGateway
{
    private string $baseUrl;

    private string $accessToken;

    private int $timeout;

    public function __construct(
        private readonly HttpFactory $http,
        ?string $baseUrl = null,
        ?string $accessToken = null,
        ?int $timeout = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.zarinpay.base_url'), '/');
        $this->accessToken = $accessToken ?? (string) config('services.zarinpay.access_token');
        $this->timeout = $timeout ?? (int) config('services.zarinpay.timeout', 15);
    }

    public function createPayment(
        int $amountIrr,
        string $merchantReference,
        string $callbackUrl,
        int|string|null $customerUserId = null,
        ?string $description = null,
        ?int $storeId = null,
    ): ZarinPayCreateResult {
        if ($amountIrr <= 0) {
            throw new PaymentException('ZarinPay amount must be positive.');
        }

        $payload = array_filter([
            'amount' => $amountIrr,
            'order_id' => $merchantReference,
            'callback_url' => $callbackUrl,
            'type' => 'card',
            'customer_user_id' => $customerUserId,
            'description' => $description,
            'store_id' => $storeId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $response = $this->request()->post('/create-payment', $payload);
        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];

        return new ZarinPayCreateResult(
            successful: $response->successful() && ($raw['success'] ?? false) === true,
            paymentLink: isset($raw['payment_link']) ? (string) $raw['payment_link'] : null,
            authority: isset($raw['authority']) ? (string) $raw['authority'] : null,
            message: isset($raw['message']) ? (string) $raw['message'] : null,
            raw: $raw,
        );
    }

    public function verifyPayment(string $authority): ZarinPayVerifyResult
    {
        if (trim($authority) === '') {
            throw new PaymentException('ZarinPay authority is required.');
        }

        $response = $this->request()->post('/verify-payment', ['authority' => $authority]);
        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $code = is_numeric($data['code'] ?? null) ? (int) $data['code'] : null;

        return new ZarinPayVerifyResult(
            successful: $response->successful() && ($raw['success'] ?? false) === true,
            code: $code,
            paymentId: $transaction['payment_id'] ?? null,
            amountIrr: is_numeric($transaction['amount'] ?? null) ? (int) $transaction['amount'] : null,
            merchantReference: isset($transaction['order_id']) ? (string) $transaction['order_id'] : null,
            authority: isset($transaction['authority']) ? (string) $transaction['authority'] : null,
            message: isset($raw['message']) ? (string) $raw['message'] : null,
            raw: $raw,
        );
    }

    private function request(): PendingRequest
    {
        if ($this->baseUrl === '' || $this->accessToken === '') {
            throw new PaymentException('ZarinPay credentials are not configured.');
        }

        return $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($this->accessToken)
            ->connectTimeout(min(5, $this->timeout))
            ->timeout($this->timeout);
    }
}
