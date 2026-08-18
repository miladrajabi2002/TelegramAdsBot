<?php

namespace Tests\Unit;

use App\Services\Payments\LiveZarinPayGateway;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveZarinPayGatewayTest extends TestCase
{
    public function test_it_maps_create_and_verify_responses_without_exposing_credentials_in_payload(): void
    {
        Http::fake([
            '*create-payment' => Http::response([
                'success' => true,
                'payment_link' => 'https://zarinmee.ir/pay/AUTH-1',
                'authority' => 'AUTH-1',
            ]),
            '*verify-payment' => Http::response([
                'success' => true,
                'data' => [
                    'code' => 100,
                    'transaction' => [
                        'payment_id' => 55,
                        'amount' => 50_000,
                        'order_id' => 'PAY-LIVE-1',
                        'authority' => 'AUTH-1',
                    ],
                ],
            ]),
        ]);
        $gateway = new LiveZarinPayGateway(
            app(HttpFactory::class),
            'https://zarinmee.ir/api',
            'test-access-token',
            5,
        );

        $created = $gateway->createPayment(
            50_000,
            'PAY-LIVE-1',
            'https://example.test/callback',
            12345,
            'Test payment',
        );
        $verified = $gateway->verifyPayment('AUTH-1');

        $this->assertTrue($created->successful);
        $this->assertSame('AUTH-1', $created->authority);
        $this->assertTrue($verified->provesPayment());
        $this->assertSame(50_000, $verified->amountIrr);
        $this->assertSame('PAY-LIVE-1', $verified->merchantReference);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/create-payment')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $request['amount'] === 50_000
                && $request['order_id'] === 'PAY-LIVE-1'
                && ! array_key_exists('access_token', $request->data());
        });
    }
}
