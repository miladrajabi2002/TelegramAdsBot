<?php

namespace Tests\Unit;

use App\Enums\KycLevel;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\CampaignRevision;
use App\Models\FundingCard;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CampaignTransitionService;
use App\Services\LedgerService;
use App\Services\Payments\Data\ZarinPayVerifyResult;
use App\Services\Payments\Exceptions\PaymentException;
use App\Services\Payments\MockZarinPayGateway;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
    }

    public function test_zarinpay_codes_100_and_repeated_callback_credit_wallet_once(): void
    {
        $user = $this->verifiedUser(401);
        [$service, $gateway] = $this->service();
        $intent = $service->createZarinPayIntent(
            $user,
            PaymentPurpose::WalletTopUp,
            500_000,
            'https://example.test/webhooks/zarinpay',
            'PAY-401',
        );
        $authority = (string) $intent->attempts->first()->authority;
        $gateway->queueVerifyResult($authority, $this->successfulVerification(
            code: 100,
            amount: 500_000,
            reference: 'PAY-401',
            authority: $authority,
        ));

        $settled = $service->verifyZarinPay('PAY-401', $authority);
        $retried = $service->verifyZarinPay('PAY-401', $authority);

        $this->assertSame(PaymentStatus::Succeeded, $settled->status);
        $this->assertSame($settled->getKey(), $retried->getKey());
        $this->assertSame(500_000, $service->walletBalance($user));
        $this->assertCount(1, $gateway->verifyCalls);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_zarinpay_code_101_can_recover_a_payment_missing_locally_without_double_credit(): void
    {
        $user = $this->verifiedUser(402);
        [$service, $gateway] = $this->service();
        $intent = $service->createZarinPayIntent(
            $user,
            PaymentPurpose::WalletTopUp,
            250_000,
            'https://example.test/webhooks/zarinpay',
            'PAY-402',
        );
        $authority = (string) $intent->attempts->first()->authority;
        $gateway->queueVerifyResult($authority, $this->successfulVerification(
            code: 101,
            amount: 250_000,
            reference: 'PAY-402',
            authority: $authority,
        ));

        $service->verifyZarinPay('PAY-402', $authority);
        $service->verifyZarinPay('PAY-402', $authority);

        $this->assertSame(250_000, $service->walletBalance($user));
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_create_payment_is_idempotent_for_the_same_merchant_reference(): void
    {
        $user = $this->verifiedUser(403);
        [$service, $gateway] = $this->service();

        $first = $service->createZarinPayIntent(
            $user,
            PaymentPurpose::WalletTopUp,
            300_000,
            'https://example.test/webhooks/zarinpay',
            'PAY-403',
        );
        $second = $service->createZarinPayIntent(
            $user,
            PaymentPurpose::WalletTopUp,
            300_000,
            'https://example.test/webhooks/zarinpay',
            'PAY-403',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertCount(1, $gateway->createCalls);
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_verified_amount_mismatch_goes_to_manual_review_without_ledger_credit(): void
    {
        $user = $this->verifiedUser(404);
        [$service, $gateway] = $this->service();
        $intent = $service->createZarinPayIntent(
            $user,
            PaymentPurpose::WalletTopUp,
            400_000,
            'https://example.test/webhooks/zarinpay',
            'PAY-404',
        );
        $authority = (string) $intent->attempts->first()->authority;
        $gateway->queueVerifyResult($authority, $this->successfulVerification(
            code: 100,
            amount: 399_999,
            reference: 'PAY-404',
            authority: $authority,
        ));

        $result = $service->verifyZarinPay('PAY-404', $authority);

        $this->assertSame(PaymentStatus::ManualReview, $result->status);
        $this->assertSame(0, $service->walletBalance($user));
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.verification_mismatch']);
    }

    public function test_direct_order_settlement_reserves_funds_and_enters_support_review_atomically(): void
    {
        $user = $this->verifiedUser(405);
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::AwaitingPayment,
            'payment_status' => OrderPaymentStatus::Unfunded,
            'media_budget_irr' => 1_000_000,
            'service_fee_irr' => 150_000,
            'total_irr' => 1_150_000,
        ]);
        [$service, $gateway, $ledger] = $this->service();
        $intent = $service->createZarinPayIntent(
            $user,
            PaymentPurpose::OrderPayment,
            1_150_000,
            'https://example.test/webhooks/zarinpay',
            'ORDER-405',
            $order,
        );
        $authority = (string) $intent->attempts->first()->authority;
        $gateway->queueVerifyResult($authority, $this->successfulVerification(
            code: 100,
            amount: 1_150_000,
            reference: 'ORDER-405',
            authority: $authority,
        ));

        $service->verifyZarinPay('ORDER-405', $authority);

        $order->refresh();
        $reserved = LedgerAccount::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->where('currency', 'IRR')
            ->where('type', 'wallet_reserved')
            ->firstOrFail();
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::SupportReview, $order->status);
        $this->assertSame(1_150_000, $ledger->balance($reserved));
        $this->assertDatabaseHas('fund_holds', [
            'order_id' => $order->getKey(),
            'amount_irr' => 1_150_000,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->getKey(),
            'to_status' => OrderStatus::SupportReview->value,
        ]);
    }

    public function test_wallet_funding_checks_available_balance_and_is_idempotent(): void
    {
        $user = $this->verifiedUser(406);
        [$service, , $ledger] = $this->service();
        $cash = $ledger->systemAccount('IRR', 'test_cash', 'debit');
        $available = $ledger->accountFor($user, 'IRR', 'wallet_available', 'credit');
        $ledger->post('seed_wallet', 'seed-wallet-406', 'Seed customer wallet', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 2_000_000],
            ['account' => $available, 'direction' => 'credit', 'amount_minor' => 2_000_000],
        ]);
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::AwaitingPayment,
            'payment_status' => OrderPaymentStatus::Unfunded,
            'total_irr' => 1_150_000,
        ]);

        $first = $service->fundOrderFromWallet($user, $order, 'wallet-fund-406');
        $second = $service->fundOrderFromWallet($user, $order, 'wallet-fund-406');

        $reserved = LedgerAccount::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->where('type', 'wallet_reserved')
            ->firstOrFail();
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(OrderStatus::SupportReview, $first->status);
        $this->assertSame(850_000, $service->walletBalance($user));
        $this->assertSame(1_150_000, $ledger->balance($reserved));
        $this->assertSame(2, LedgerTransaction::query()->count());
        $this->assertDatabaseCount('fund_holds', 1);
    }

    public function test_provider_neutral_settlement_supports_a_verified_nowpayments_ipn(): void
    {
        $user = $this->verifiedUser(407);
        [$service] = $this->service();
        $intent = PaymentIntent::create([
            'user_id' => $user->getKey(),
            'purpose' => PaymentPurpose::WalletTopUp,
            'provider' => 'nowpayments',
            'merchant_reference' => 'NOW-407',
            'amount_minor' => 725_000,
            'currency' => 'IRR',
            'status' => PaymentStatus::Pending,
        ]);

        $first = $service->settleSuccessfulIntent($intent, 'np-payment-407', [
            'amount_minor' => 725_000,
            'currency' => 'IRR',
            'merchant_reference' => 'NOW-407',
            'payment_status' => 'finished',
        ]);
        $second = $service->settleSuccessfulIntent($intent, 'np-payment-407', [
            'amount_minor' => 725_000,
            'currency' => 'IRR',
            'merchant_reference' => 'NOW-407',
            'payment_status' => 'finished',
        ]);

        $this->assertSame(PaymentStatus::Succeeded, $first->status);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(725_000, $service->walletBalance($user));
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_order_funding_consumes_restricted_ad_credit_before_available_wallet(): void
    {
        $user = $this->verifiedUser(409);
        [$service, , $ledger] = $this->service();
        $cash = $ledger->systemAccount('IRR', 'test_restricted_cash', 'debit');
        $available = $ledger->accountFor($user, 'IRR', 'wallet_available', 'credit');
        $restricted = $ledger->accountFor($user, 'IRR', 'ad_credit_restricted', 'credit');
        $ledger->post('seed_ad_credit', 'seed-ad-credit-409', 'Seed customer balances', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 1_300_000],
            ['account' => $restricted, 'direction' => 'credit', 'amount_minor' => 800_000],
            ['account' => $available, 'direction' => 'credit', 'amount_minor' => 500_000],
        ]);
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::AwaitingPayment,
            'payment_status' => OrderPaymentStatus::Unfunded,
            'total_irr' => 1_150_000,
        ]);

        $funded = $service->fundOrderFromWallet($user, $order, 'restricted-first-409');

        $reserved = LedgerAccount::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->where('type', 'wallet_reserved')
            ->firstOrFail();
        $this->assertSame(OrderPaymentStatus::Paid, $funded->payment_status);
        $this->assertSame(0, $service->restrictedAdCreditBalance($user));
        $this->assertSame(150_000, $service->walletBalance($user));
        $this->assertSame(1_150_000, $ledger->balance($reserved));
    }

    public function test_insufficient_combined_balance_rolls_back_order_reservation(): void
    {
        $user = $this->verifiedUser(410);
        [$service, , $ledger] = $this->service();
        $cash = $ledger->systemAccount('IRR', 'test_insufficient_cash', 'debit');
        $restricted = $ledger->accountFor($user, 'IRR', 'ad_credit_restricted', 'credit');
        $ledger->post('seed_small_credit', 'seed-small-credit-410', 'Seed limited advertising credit', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 100_000],
            ['account' => $restricted, 'direction' => 'credit', 'amount_minor' => 100_000],
        ]);
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::AwaitingPayment,
            'payment_status' => OrderPaymentStatus::Unfunded,
            'total_irr' => 300_000,
        ]);

        try {
            $service->fundOrderFromWallet($user, $order, 'insufficient-410');
            $this->fail('Insufficient combined balance should throw.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString('insufficient', strtolower($exception->getMessage()));
        }

        $this->assertSame(OrderPaymentStatus::Unfunded, $order->refresh()->payment_status);
        $this->assertSame(OrderStatus::AwaitingPayment, $order->status);
        $this->assertDatabaseCount('fund_holds', 0);
        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertSame(100_000, $service->restrictedAdCreditBalance($user));
    }

    public function test_telegram_rejection_reconciliation_posts_final_spend_and_restricted_credit_once(): void
    {
        $user = $this->verifiedUser(411);
        $admin = Admin::create([
            'name' => 'Finance Admin',
            'email' => 'finance-411@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        [$service, , $ledger] = $this->service();
        $cash = $ledger->systemAccount('IRR', 'test_reconciliation_cash', 'debit');
        $available = $ledger->accountFor($user, 'IRR', 'wallet_available', 'credit');
        $ledger->post('seed_wallet', 'seed-wallet-411', 'Seed customer wallet', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 1_150_000],
            ['account' => $available, 'direction' => 'credit', 'amount_minor' => 1_150_000],
        ]);
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::AwaitingPayment,
            'payment_status' => OrderPaymentStatus::Unfunded,
            'media_budget_irr' => 1_000_000,
            'service_fee_irr' => 150_000,
            'gateway_fee_irr' => 0,
            'total_irr' => 1_150_000,
        ]);
        $revision = CampaignRevision::create([
            'order_id' => $order->getKey(),
            'revision_no' => 1,
            'internal_title' => 'Rejected campaign',
            'ad_text' => 'A valid test advertisement',
            'destination_type' => 'channel',
            'destination_url' => 'https://t.me/example_channel',
            'language' => 'en',
        ]);
        $order->update(['current_revision_id' => $revision->getKey()]);
        $order = $service->fundOrderFromWallet($user, $order, 'wallet-fund-411');
        $transitions = new CampaignTransitionService(new AuditLogger);
        $order = $transitions->transition($order, OrderStatus::QueuedForTelegram, $admin);
        $order = $transitions->recordTelegramSubmission($order, $admin, 'tg-rejected-411');
        $order = $transitions->recordTelegramDecision($order, $admin, false, 'Telegram policy rejection.');

        $first = $service->reconcileTelegramRejection($order, $admin, 200_000, 'Matched to Telegram statement.');
        $second = $service->reconcileTelegramRejection($first, $admin, 200_000, 'Idempotent retry.');

        $reserved = $ledger->accountFor($user, 'IRR', 'wallet_reserved', 'credit');
        $telegramSettlement = $ledger->systemAccount('IRR', 'telegram_media_settlement', 'credit');
        $serviceRevenue = $ledger->systemAccount('IRR', 'managed_service_revenue', 'credit');
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(0, $ledger->balance($reserved));
        $this->assertSame(800_000, $service->restrictedAdCreditBalance($user));
        $this->assertSame(200_000, $ledger->balance($telegramSettlement));
        $this->assertSame(150_000, $ledger->balance($serviceRevenue));
        $this->assertDatabaseHas('fund_holds', ['order_id' => $order->getKey(), 'status' => 'reconciled']);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'reconcile_telegram_rejection',
            'status' => 'completed',
        ]);
        $this->assertSame(3, LedgerTransaction::query()->count());

        try {
            $service->reconcileTelegramRejection($order, $admin, 300_000);
            $this->fail('A completed reconciliation cannot be reposted with a different amount.');
        } catch (PaymentException $exception) {
            $this->assertStringContainsString('different spend amount', $exception->getMessage());
        }

        $this->assertSame(3, LedgerTransaction::query()->count());
    }

    public function test_completed_campaign_reconciliation_releases_hold_and_posts_actual_delivery(): void
    {
        $user = $this->verifiedUser(412);
        $admin = Admin::create([
            'name' => 'Completion Admin',
            'email' => 'finance-412@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        [$service, , $ledger] = $this->service();
        $cash = $ledger->systemAccount('IRR', 'test_completion_cash', 'debit');
        $available = $ledger->accountFor($user, 'IRR', 'wallet_available', 'credit');
        $ledger->post('seed_wallet', 'seed-wallet-412', 'Seed customer wallet', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 1_150_000],
            ['account' => $available, 'direction' => 'credit', 'amount_minor' => 1_150_000],
        ]);
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::AwaitingPayment,
            'payment_status' => OrderPaymentStatus::Unfunded,
            'media_budget_irr' => 1_000_000,
            'service_fee_irr' => 150_000,
            'gateway_fee_irr' => 0,
            'total_irr' => 1_150_000,
        ]);
        $revision = CampaignRevision::create([
            'order_id' => $order->getKey(),
            'revision_no' => 1,
            'internal_title' => 'Completed campaign',
            'ad_text' => 'A completed test advertisement',
            'destination_type' => 'channel',
            'destination_url' => 'https://t.me/example_channel',
            'language' => 'en',
        ]);
        $order->update(['current_revision_id' => $revision->getKey()]);
        $order = $service->fundOrderFromWallet($user, $order, 'wallet-fund-412');
        $transitions = new CampaignTransitionService(new AuditLogger);
        $order = $transitions->transition($order, OrderStatus::QueuedForTelegram, $admin);
        $order = $transitions->recordTelegramSubmission($order, $admin, 'tg-completed-412');
        $order = $transitions->recordTelegramDecision($order, $admin, true);
        $order = $transitions->transition($order, OrderStatus::Active, $admin);
        $order = $transitions->transition($order, OrderStatus::Completed, $admin);

        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'reconcile_completed_campaign',
            'status' => 'open',
        ]);

        $service->reconcileCompletedCampaign($order, $admin, 900_000, 'Matched final delivery statement.');

        $reserved = $ledger->accountFor($user, 'IRR', 'wallet_reserved', 'credit');
        $this->assertSame(0, $ledger->balance($reserved));
        $this->assertSame(100_000, $service->restrictedAdCreditBalance($user));
        $this->assertSame(900_000, $ledger->balance($ledger->systemAccount('IRR', 'telegram_media_settlement', 'credit')));
        $this->assertSame(150_000, $ledger->balance($ledger->systemAccount('IRR', 'managed_service_revenue', 'credit')));
        $this->assertDatabaseHas('fund_holds', ['order_id' => $order->getKey(), 'status' => 'reconciled']);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'reconcile_completed_campaign',
            'status' => 'completed',
        ]);
        $this->assertSame(3, LedgerTransaction::query()->count());
    }

    public function test_unverified_user_cannot_create_a_rial_payment(): void
    {
        $user = User::create([
            'telegram_user_id' => 408,
            'display_name' => 'Base User',
            'kyc_level' => KycLevel::Base,
        ]);
        [$service] = $this->service();

        $this->expectException(PaymentException::class);
        $service->createZarinPayIntent(
            $user,
            PaymentPurpose::WalletTopUp,
            100_000,
            'https://example.test/webhooks/zarinpay',
            'PAY-408',
        );
    }

    /** @return array{PaymentService, MockZarinPayGateway, LedgerService} */
    private function service(): array
    {
        $gateway = new MockZarinPayGateway;
        $ledger = new LedgerService;
        $audit = new AuditLogger;
        $campaigns = new CampaignTransitionService($audit);

        return [new PaymentService($ledger, $campaigns, $gateway, $audit), $gateway, $ledger];
    }

    private function verifiedUser(int $telegramId): User
    {
        $user = User::create([
            'telegram_user_id' => $telegramId,
            'display_name' => "Verified {$telegramId}",
            'phone' => '+98912000'.$telegramId,
            'phone_verified_at' => now(),
            'kyc_level' => KycLevel::RialVerified,
            'account_status' => 'active',
        ]);
        FundingCard::create([
            'user_id' => $user->getKey(),
            'pan_encrypted' => '603799000000'.$telegramId,
            'pan_hmac' => hash_hmac('sha256', '603799000000'.$telegramId, 'test-key'),
            'bin' => '603799',
            'last4' => substr((string) $telegramId, -4),
            'holder_name_encrypted' => "Verified {$telegramId}",
            'status' => 'approved',
            'verified_at' => now(),
        ]);

        return $user;
    }

    private function successfulVerification(
        int $code,
        int $amount,
        string $reference,
        string $authority,
    ): ZarinPayVerifyResult {
        return new ZarinPayVerifyResult(
            successful: true,
            code: $code,
            paymentId: 9001,
            amountIrr: $amount,
            merchantReference: $reference,
            authority: $authority,
            raw: [
                'success' => true,
                'data' => [
                    'code' => $code,
                    'transaction' => [
                        'payment_id' => 9001,
                        'amount' => $amount,
                        'order_id' => $reference,
                        'authority' => $authority,
                    ],
                ],
            ],
        );
    }
}
