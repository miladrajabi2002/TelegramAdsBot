<?php

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NowPaymentsClient
{
    /** @return array<string, mixed> */
    public function createInvoice(
        float $usdAmount,
        string $merchantReference,
        string $description,
        ?string $successUrl = null,
        ?string $cancelUrl = null,
    ): array {
        if (! config('services.nowpayments.enabled')) {
            throw new RuntimeException('NOWPayments is not enabled.');
        }

        return $this->http()->post('/invoice', [
            'price_amount' => round($usdAmount, 2),
            'price_currency' => 'usd',
            'order_id' => $merchantReference,
            'order_description' => $description,
            'ipn_callback_url' => route('webhooks.nowpayments'),
            'success_url' => $successUrl ?? route('app.wallet.index', ['payment' => 'pending']),
            'cancel_url' => $cancelUrl ?? route('app.wallet.index', ['payment' => 'cancelled']),
            'is_fixed_rate' => true,
            'is_fee_paid_by_user' => false,
        ])->throw()->json();
    }

    /** @param array<string, mixed> $payload */
    public function validIpn(array $payload, ?string $receivedSignature): bool
    {
        $secret = trim((string) config('services.nowpayments.ipn_secret'));
        if ($secret === '' || ! is_string($receivedSignature) || $receivedSignature === '') {
            return false;
        }

        $this->sortRecursively($payload);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $json, $secret), strtolower($receivedSignature));
    }

    /** @param array<string, mixed> $invoice */
    public function trustedInvoiceUrl(array $invoice): string
    {
        $url = $invoice['invoice_url'] ?? null;
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('NOWPayments returned an invalid invoice URL.');
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = (array) config('services.nowpayments.invoice_hosts', ['nowpayments.io']);
        $trustedHost = collect($allowedHosts)->contains(
            fn (mixed $allowed): bool => $host === strtolower((string) $allowed)
                || str_ends_with($host, '.'.strtolower((string) $allowed)),
        );
        if (($parts['scheme'] ?? null) !== 'https'
            || $host === ''
            || ! $trustedHost
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('NOWPayments invoice URL is outside the configured trusted hosts.');
        }

        return $url;
    }

    private function http(): PendingRequest
    {
        $apiKey = trim((string) config('services.nowpayments.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('NOWPAYMENTS_API_KEY is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('services.nowpayments.base_url'), '/'))
            ->withHeaders(['x-api-key' => $apiKey])->acceptJson()->asJson()->timeout(20)->retry(2, 300);
    }

    /** @param array<string, mixed> $payload */
    private function sortRecursively(array &$payload): void
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }
}
