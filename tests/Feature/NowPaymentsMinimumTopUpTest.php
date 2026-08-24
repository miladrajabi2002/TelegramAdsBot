<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NowPaymentsMinimumTopUpTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function wallet_top_up_rejects_an_amount_below_ten_dollars(): void
    {
        config([
            'services.nowpayments.enabled' => true,
            'services.nowpayments.minimum_top_up_usd' => 10,
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('app.wallet.deposit'), [
            'provider' => 'nowpayments',
            'amount_usd' => '9.99',
        ]);

        $response->assertSessionHasErrors('amount_usd');
        $this->assertSame(0, PaymentIntent::query()->count());
    }

    #[Test]
    public function wallet_displays_the_ten_dollar_minimum(): void
    {
        config([
            'services.nowpayments.enabled' => true,
            'services.nowpayments.minimum_top_up_usd' => 10,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('app.wallet.index'))
            ->assertOk()
            ->assertSee('حداقل شارژ 10 دلار است.')
            ->assertSee('min="10"', false);
    }

    #[Test]
    public function wallet_top_up_accepts_exactly_ten_dollars(): void
    {
        config([
            'services.nowpayments.enabled' => true,
            'services.nowpayments.api_key' => 'test-api-key',
            'services.nowpayments.minimum_top_up_usd' => 10,
            'services.nowpayments.invoice_hosts' => ['nowpayments.io'],
        ]);
        Http::fake([
            'api.nowpayments.io/*' => Http::response([
                'id' => 'invoice-10',
                'invoice_url' => 'https://nowpayments.io/payment/invoice-10',
            ]),
            'api.exir.io/*' => Http::response(['last' => '60000']),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('app.wallet.deposit'), [
                'provider' => 'nowpayments',
                'amount_usd' => '10.00',
            ])
            ->assertRedirect('https://nowpayments.io/payment/invoice-10');

        $intent = PaymentIntent::query()->sole();
        $this->assertEquals(10.0, $intent->metadata['usd_amount']);
        $this->assertSame('pending', $intent->status->value);
    }
}
