<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\PricingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CampaignCreationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function customer_can_create_an_immutable_priced_order_draft(): void
    {
        PricingRule::create([
            'service_markup_bps' => 1500,
            'gateway_fee_bps' => 0,
            'minimum_order_irr' => 1_000_000,
            'is_active' => true,
            'effective_from' => now(),
        ]);
        $user = User::factory()->create(['locale' => 'fa']);

        $response = $this->actingAs($user)->post(route('app.campaigns.store'), [
            'internal_title' => 'کمپین تست',
            'ad_text' => 'محصول جدید ما را در تلگرام ببینید.',
            'destination_type' => 'bot',
            'destination_url' => 'https://t.me/example_test_bot',
            'placement_type' => 'broad',
            'impression_goal' => 10000,
            'frequency_cap' => 2,
            'plan' => 'standard',
            'cpm_gram' => 0.2,
            'media_budget_toman' => 1_000_000,
            'terms_accepted' => '1',
        ]);

        $order = $user->orders()->firstOrFail();
        $response->assertRedirect(route('app.campaigns.show', $order));
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertSame(10_000_000, $order->media_budget_irr);
        $this->assertSame(1_500_000, $order->service_fee_irr);
        $this->assertSame(11_500_000, $order->total_irr);
        $this->assertSame('600000.0000', $order->usd_to_irr_rate);
        $this->assertSame('3.25000000', $order->gram_to_usd_rate);
        $this->assertSame('admin_settings', $order->rate_source);
        $this->assertNotNull($order->quoted_at);
        $this->assertSame('کمپین تست', $order->currentRevision->internal_title);
    }

    #[Test]
    public function structural_telegram_ad_violations_are_rejected_before_payment(): void
    {
        PricingRule::create([
            'service_markup_bps' => 1500, 'gateway_fee_bps' => 0,
            'minimum_order_irr' => 1_000_000, 'is_active' => true, 'effective_from' => now(),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('app.campaigns.create'))->post(route('app.campaigns.store'), [
            'internal_title' => 'Bad ad',
            'ad_text' => "Line one\nLine two",
            'destination_type' => 'bot',
            'destination_url' => 'https://bit.ly/example',
            'placement_type' => 'broad',
            'plan' => 'standard',
            'cpm_gram' => 0.1,
            'media_budget_toman' => 1_000_000,
            'terms_accepted' => '1',
        ])->assertRedirect(route('app.campaigns.create'))->assertSessionHasErrors('ad_text');

        $this->assertDatabaseCount('orders', 0);
    }
}
