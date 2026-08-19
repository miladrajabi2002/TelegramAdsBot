<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\ZarinPayGateway;
use App\Services\Payments\Data\ZarinPayCreateResult;
use App\Services\Payments\Data\ZarinPayVerifyResult;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

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

        try {
            $response = $this->request()->post('/create-payment', $payload);
        } catch (ConnectionException $exception) {
            // Network-level failure (DNS, connect timeout, TLS, etc.).
            // Without this catch, Laravel's HTTP client wraps the error in
            // a generic RuntimeException and the operator just sees
            // "ZarinPay rejected payment creation." with no clue that the
            // request never reached the gateway.
            Log::error('ZarinPay create-payment network failure', [
                'merchant_reference' => $merchantReference,
                'amount_irr' => $amountIrr,
                'base_url' => $this->baseUrl,
                'error' => $exception->getMessage(),
            ]);

            // Re-throw as a PaymentException so PaymentService can mark the
            // intent as ManualReview and the operator can see it in the
            // diagnose command. We keep the original exception as previous
            // so the stack trace is preserved.
            throw new PaymentException(
                'ZarinPay gateway is unreachable: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];

        // Log the FULL response when something looks off so the operator
        // can see exactly what ZarinPay returned. We log at debug level by
        // default (to avoid spamming production logs with PII), and at
        // warning level when the request was not successful.
        if (! $response->successful() || ($raw['success'] ?? null) !== true) {
            Log::warning('ZarinPay create-payment was not successful', [
                'merchant_reference' => $merchantReference,
                'amount_irr' => $amountIrr,
                'http_status' => $response->status(),
                'raw_response' => $this->redactForLog($raw),
            ]);
        } else {
            Log::debug('ZarinPay create-payment response', [
                'merchant_reference' => $merchantReference,
                'http_status' => $response->status(),
                'has_payment_link' => isset($raw['payment_link']) || isset($raw['data']['payment_link']),
                'has_authority' => isset($raw['authority']) || isset($raw['data']['authority']),
            ]);
        }

        // ─── Normalize response shape ────────────────────────────────────
        // ZarinPay's API contract returns the top-level fields `success`,
        // `payment_link`, `authority`, and `message`. However, in practice
        // we've observed several response shapes in the wild:
        //
        //   1. Flat:       { success, payment_link, authority, message }
        //   2. Nested:     { success, data: { payment_link, authority }, message }
        //   3. Zarinpal-style: { data: { authority, code }, errors: [] }
        //   4. Modern API: { ok, data: { link, token } }
        //
        // We accept any of these so the gateway stays resilient when the
        // upstream provider tweaks their response format. The PaymentService
        // still validates the final URL via isTrustedZarinPayUrl() so we
        // never blindly trust a foreign hostname.
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];

        $success = ($raw['success'] ?? null) === true
            || ($raw['ok'] ?? null) === true
            || ($data['success'] ?? null) === true
            || ($raw['data']['code'] ?? null) === 100
            || ($data['code'] ?? null) === 100;

        $paymentLink = $this->pickString([
            $raw['payment_link'] ?? null,
            $raw['payment_url'] ?? null,
            $raw['link'] ?? null,
            $raw['url'] ?? null,
            $data['payment_link'] ?? null,
            $data['payment_url'] ?? null,
            $data['link'] ?? null,
            $data['url'] ?? null,
            $raw['redirect_url'] ?? null,
            $data['redirect_url'] ?? null,
        ]);

        // If the gateway returned only an authority, build the standard
        // ZarinPal/zarinmee payment link ourselves. This is the contract
        // documented at https://github.com/miladrajabi2002/zarinpay-doc:
        //   {base}/payment/{authority}  →  public checkout page.
        $authority = $this->pickString([
            $raw['authority'] ?? null,
            $data['authority'] ?? null,
            $raw['token'] ?? null,
            $data['token'] ?? null,
            $raw['Authority'] ?? null,
        ]);

        if ($paymentLink === null && $authority !== null && $this->baseUrl !== '') {
            // Build a payment link on the configured base host (without /api).
            // e.g. https://zarinmee.ir/api  →  https://zarinmee.ir/payment/{authority}
            $baseHost = preg_replace('#/api$#', '', $this->baseUrl);
            $candidate = rtrim($baseHost, '/').'/payment/'.$authority;
            // Only use it if it parses as a real URL.
            if (filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
                $paymentLink = $candidate;
            }
        }

        $message = $this->pickString([
            $raw['message'] ?? null,
            $data['message'] ?? null,
            $raw['error'] ?? null,
            $data['error'] ?? null,
            is_array($raw['errors'] ?? null) && $raw['errors']
                ? (string) (collect($raw['errors'])->flatten()->first() ?: '')
                : null,
        ]);

        return new ZarinPayCreateResult(
            successful: $response->successful() && $success,
            paymentLink: $paymentLink,
            authority: $authority,
            message: $message,
            raw: $raw,
        );
    }

    public function verifyPayment(string $authority): ZarinPayVerifyResult
    {
        if (trim($authority) === '') {
            throw new PaymentException('ZarinPay authority is required.');
        }

        try {
            $response = $this->request()->post('/verify-payment', ['authority' => $authority]);
        } catch (ConnectionException $exception) {
            Log::error('ZarinPay verify-payment network failure', [
                'authority' => $authority,
                'base_url' => $this->baseUrl,
                'error' => $exception->getMessage(),
            ]);

            throw new PaymentException(
                'ZarinPay gateway is unreachable during verify: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $raw = $response->json();
        $raw = is_array($raw) ? $raw : [];
        $data = is_array($raw['data'] ?? null) ? $raw['data'] : [];
        $transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
        $code = is_numeric($data['code'] ?? null) ? (int) $data['code'] : null;

        // Some ZarinPal-compatible gateways nest transaction data one level
        // deeper or use different field names. Be permissive about the
        // shape, strict about the success code (100 or 101).
        $transactionAlt = is_array($raw['transaction'] ?? null) ? $raw['transaction'] : [];

        $successful = $response->successful()
            && (($raw['success'] ?? null) === true
                || ($raw['ok'] ?? null) === true
                || in_array($code, [100, 101], true));

        $amount = $transaction['amount'] ?? $transactionAlt['amount'] ?? $data['amount'] ?? null;
        $payId = $transaction['payment_id'] ?? $transactionAlt['payment_id'] ?? $data['payment_id'] ?? null;
        $ref = $transaction['order_id'] ?? $transactionAlt['order_id'] ?? $data['order_id'] ?? null;
        $auth = $transaction['authority'] ?? $transactionAlt['authority'] ?? $authority;

        $message = $this->pickString([
            $raw['message'] ?? null,
            $data['message'] ?? null,
            $raw['error'] ?? null,
        ]);

        if (! $successful) {
            Log::warning('ZarinPay verify-payment was not successful', [
                'authority' => $authority,
                'http_status' => $response->status(),
                'code' => $code,
                'message' => $message,
                'raw_response' => $this->redactForLog($raw),
            ]);
        }

        return new ZarinPayVerifyResult(
            successful: $successful,
            code: $code,
            paymentId: $payId,
            amountIrr: is_numeric($amount) ? (int) $amount : null,
            merchantReference: isset($ref) ? (string) $ref : null,
            authority: isset($auth) ? (string) $auth : null,
            message: $message,
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

    /**
     * Pick the first non-empty string from a list of candidates.
     *
     * @param  array<mixed>  $candidates
     */
    private function pickString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
            if (is_int($candidate) && $candidate > 0) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Strip sensitive fields before logging the raw gateway response.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactForLog(array $payload): array
    {
        $blocked = ['api_key', 'token', 'secret', 'signature', 'pan', 'card_number', 'cvv', 'password', 'access_token'];

        $redact = static function (array $values) use (&$redact, $blocked): array {
            foreach ($values as $key => $value) {
                $keyName = strtolower((string) $key);

                $hit = false;
                foreach ($blocked as $needle) {
                    if (str_contains($keyName, $needle)) {
                        $hit = true;
                        break;
                    }
                }

                if ($hit) {
                    $values[$key] = '[REDACTED]';
                } elseif (is_array($value)) {
                    $values[$key] = $redact($value);
                }
            }

            return $values;
        };

        return $redact($payload);
    }
}
