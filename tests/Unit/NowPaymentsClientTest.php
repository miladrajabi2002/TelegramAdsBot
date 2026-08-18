<?php

namespace Tests\Unit;

use App\Services\Payments\NowPaymentsClient;
use RuntimeException;
use Tests\TestCase;

class NowPaymentsClientTest extends TestCase
{
    public function test_only_https_invoice_urls_on_configured_hosts_are_accepted(): void
    {
        config(['services.nowpayments.invoice_hosts' => ['nowpayments.io']]);
        $client = new NowPaymentsClient;

        $this->assertSame(
            'https://pay.nowpayments.io/invoice/123',
            $client->trustedInvoiceUrl(['invoice_url' => 'https://pay.nowpayments.io/invoice/123']),
        );

        $this->expectException(RuntimeException::class);
        $client->trustedInvoiceUrl(['invoice_url' => 'https://nowpayments.io.evil.example/invoice/123']);
    }

    public function test_ipn_signature_uses_recursively_sorted_json(): void
    {
        config(['services.nowpayments.ipn_secret' => 'test-ipn-secret']);
        $payload = ['z' => 1, 'nested' => ['b' => 2, 'a' => 1], 'a' => 'first'];
        $canonical = ['a' => 'first', 'nested' => ['a' => 1, 'b' => 2], 'z' => 1];
        $signature = hash_hmac(
            'sha512',
            json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'test-ipn-secret',
        );

        $this->assertTrue((new NowPaymentsClient)->validIpn($payload, $signature));
        $this->assertFalse((new NowPaymentsClient)->validIpn($payload, str_repeat('0', 128)));
    }
}
