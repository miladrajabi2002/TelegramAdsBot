<?php

namespace Tests\Unit;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\TelegramSubmissionStatus;
use App\Models\Admin;
use App\Models\CampaignRevision;
use App\Models\Order;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CampaignTransitionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_telegram_workflow_logs_events_and_synchronizes_operator_tasks(): void
    {
        $user = $this->user(301);
        $admin = $this->admin();
        $order = $this->reviewableOrder($user);
        $service = new CampaignTransitionService(new AuditLogger);

        $order = $service->transition($order, OrderStatus::QueuedForTelegram, $admin);

        $this->assertSame(OrderStatus::QueuedForTelegram, $order->status);
        $this->assertTrue($order->currentRevision->refresh()->is_locked);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'submit_telegram_ad',
            'status' => 'open',
        ]);

        $order = $service->recordTelegramSubmission(
            $order,
            $admin,
            'tg-ad-123',
            'Primary Ads Account',
        );

        $this->assertSame(OrderStatus::TelegramReview, $order->status);
        $this->assertDatabaseHas('telegram_submissions', [
            'campaign_revision_id' => $order->current_revision_id,
            'external_ad_id' => 'tg-ad-123',
            'status' => TelegramSubmissionStatus::InReview->value,
        ]);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'submit_telegram_ad',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'sync_telegram_review',
            'status' => 'open',
        ]);

        $order = $service->recordTelegramDecision($order, $admin, true);

        $this->assertSame(OrderStatus::TelegramApproved, $order->status);
        $this->assertDatabaseHas('telegram_submissions', [
            'external_ad_id' => 'tg-ad-123',
            'status' => TelegramSubmissionStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'sync_telegram_review',
            'status' => 'completed',
        ]);
        $this->assertDatabaseCount('order_status_events', 3);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.status_changed']);
    }

    public function test_pause_is_a_request_until_an_operator_confirms_it(): void
    {
        $user = $this->user(302);
        $admin = $this->admin();
        $service = new CampaignTransitionService(new AuditLogger);
        $order = $this->approvedOrder($user, $admin, $service);
        $order->forceFill(['planned_start_at' => now()->addHour()])->save();
        $order = $service->transition($order, OrderStatus::Scheduled, $admin);
        $order = $service->transition($order, OrderStatus::Active, $admin);

        $order = $service->transition($order, OrderStatus::PauseRequested, $user);

        $this->assertSame(OrderStatus::PauseRequested, $order->status);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'pause_telegram_ad',
            'status' => 'open',
        ]);

        $order = $service->transition($order, OrderStatus::Paused, $admin);

        $this->assertSame(OrderStatus::Paused, $order->status);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'pause_telegram_ad',
            'status' => 'completed',
        ]);
    }

    public function test_telegram_rejection_opens_a_dedicated_financial_reconciliation_task(): void
    {
        $user = $this->user(307);
        $admin = $this->admin();
        $service = new CampaignTransitionService(new AuditLogger);
        $order = $service->transition($this->reviewableOrder($user), OrderStatus::QueuedForTelegram, $admin);
        $order = $service->recordTelegramSubmission($order, $admin, 'tg-ad-rejected');

        $order = $service->recordTelegramDecision(
            $order,
            $admin,
            false,
            'Telegram policy rejection.',
        );

        $this->assertSame(OrderStatus::TelegramRejected, $order->status);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'sync_telegram_review',
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('operator_tasks', [
            'order_id' => $order->getKey(),
            'type' => 'reconcile_telegram_rejection',
            'status' => 'open',
        ]);
    }

    public function test_invalid_transition_is_rejected_without_an_event(): void
    {
        $user = $this->user(303);
        $admin = $this->admin();
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::Draft,
            'payment_status' => OrderPaymentStatus::Unfunded,
        ]);
        $service = new CampaignTransitionService(new AuditLogger);

        try {
            $service->transition($order, OrderStatus::Active, $admin);
            $this->fail('Invalid transition should throw.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Invalid campaign transition', $exception->getMessage());
        }

        $this->assertDatabaseCount('order_status_events', 0);
    }

    public function test_repeating_current_status_does_not_duplicate_events_or_tasks(): void
    {
        $user = $this->user(304);
        $admin = $this->admin();
        $service = new CampaignTransitionService(new AuditLogger);
        $order = $service->transition($this->reviewableOrder($user), OrderStatus::QueuedForTelegram, $admin);

        $service->transition($order, OrderStatus::QueuedForTelegram, $admin);

        $this->assertDatabaseCount('order_status_events', 1);
        $this->assertDatabaseCount('operator_tasks', 1);
    }

    public function test_customer_cannot_transition_another_customers_order(): void
    {
        $owner = $this->user(305);
        $other = $this->user(306);
        $order = Order::create([
            'user_id' => $owner->getKey(),
            'status' => OrderStatus::Draft,
            'payment_status' => OrderPaymentStatus::Unfunded,
        ]);

        $this->expectException(DomainException::class);
        (new CampaignTransitionService(new AuditLogger))
            ->transition($order, OrderStatus::AwaitingPayment, $other);
    }

    private function approvedOrder(
        User $user,
        Admin $admin,
        CampaignTransitionService $service,
    ): Order {
        $order = $service->transition($this->reviewableOrder($user), OrderStatus::QueuedForTelegram, $admin);
        $order = $service->recordTelegramSubmission($order, $admin, 'tg-ad-approved');

        return $service->recordTelegramDecision($order, $admin, true);
    }

    private function reviewableOrder(User $user): Order
    {
        $order = Order::create([
            'user_id' => $user->getKey(),
            'status' => OrderStatus::SupportReview,
            'payment_status' => OrderPaymentStatus::Paid,
            'media_budget_irr' => 1_000_000,
            'total_irr' => 1_150_000,
            'funded_at' => now(),
        ]);
        $revision = CampaignRevision::create([
            'order_id' => $order->getKey(),
            'revision_no' => 1,
            'internal_title' => 'Test campaign',
            'ad_text' => 'A clear Telegram advertisement',
            'destination_type' => 'channel',
            'destination_url' => 'https://t.me/example_channel',
            'language' => 'en',
        ]);
        $order->forceFill(['current_revision_id' => $revision->getKey()])->save();

        return $order->refresh();
    }

    private function user(int $telegramId): User
    {
        return User::create([
            'telegram_user_id' => $telegramId,
            'display_name' => "User {$telegramId}",
            'locale' => 'fa',
        ]);
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Campaign Admin',
            'email' => 'campaign@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
