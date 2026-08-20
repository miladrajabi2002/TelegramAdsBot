<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\TelegramSubmissionStatus;
use App\Models\Admin;
use App\Models\CampaignRevision;
use App\Models\OperatorTask;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\TelegramSubmission;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignTransitionService
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['awaiting_payment', 'cancelled_by_user'],
        'awaiting_payment' => ['support_review', 'cancelled_by_user', 'manual_attention'],
        'support_review' => ['changes_requested', 'queued_for_telegram', 'cancelled_by_support', 'manual_attention'],
        'changes_requested' => ['support_review', 'cancelled_by_user', 'manual_attention'],
        'queued_for_telegram' => ['telegram_review', 'cancelled_by_support', 'manual_attention'],
        'telegram_review' => ['telegram_approved', 'telegram_rejected', 'manual_attention'],
        'telegram_approved' => ['scheduled', 'active', 'manual_attention'],
        'scheduled' => ['active', 'pause_requested', 'manual_attention'],
        'active' => ['pause_requested', 'completed', 'manual_attention'],
        'pause_requested' => ['paused', 'active', 'manual_attention'],
        'paused' => ['resume_requested', 'completed', 'cancelled_by_user', 'manual_attention'],
        'resume_requested' => ['active', 'scheduled', 'paused', 'manual_attention'],
        'telegram_rejected' => ['changes_requested', 'completed', 'manual_attention'],
        'manual_attention' => [
            'support_review', 'queued_for_telegram', 'telegram_review', 'telegram_approved',
            'scheduled', 'active', 'paused', 'completed', 'cancelled_by_support',
        ],
    ];

    /** @var array<string, list<string>> */
    private const USER_TRANSITIONS = [
        'draft' => ['awaiting_payment', 'cancelled_by_user'],
        'awaiting_payment' => ['cancelled_by_user'],
        'changes_requested' => ['support_review', 'cancelled_by_user'],
        'scheduled' => ['pause_requested'],
        'active' => ['pause_requested'],
        'paused' => ['resume_requested', 'cancelled_by_user'],
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly MiniAppNotifier $notifier,
    ) {}

    /**
     * Move an order through the canonical campaign state machine. Repeating the
     * same target state is an idempotent no-op.
     *
     * @param  array<string, mixed>  $context
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        ?Model $actor = null,
        ?string $reasonCode = null,
        ?string $note = null,
        ?string $correlationId = null,
        array $context = [],
    ): Order {
        if (! $order->exists) {
            throw new DomainException('Order must be persisted before it can transition.');
        }

        $correlationId ??= (string) Str::uuid();

        if (! Str::isUuid($correlationId)) {
            throw new DomainException('Campaign transition correlation ID must be a UUID.');
        }

        return DB::transaction(function () use (
            $order,
            $to,
            $actor,
            $reasonCode,
            $note,
            $correlationId,
            $context,
        ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $from = $locked->status;
            $this->assertActorBelongsToOperation($locked, $actor);

            if ($from === $to) {
                return $locked;
            }

            $this->assertActorCanTransition($locked, $from, $to, $actor);
            $this->assertAllowed($from, $to);
            $this->assertDecisionReason($to, $reasonCode, $note);
            $this->assertPrerequisites($locked, $to);

            if ($to === OrderStatus::QueuedForTelegram) {
                $revision = $this->lockedCurrentRevision($locked);
                $revision->forceFill(['is_locked' => true])->save();
            }

            $locked->status = $to;

            if ($to === OrderStatus::Completed) {
                $locked->completed_at = now();
            } elseif ($from === OrderStatus::Completed) {
                $locked->completed_at = null;
            }

            $locked->save();
            $this->synchronizeLatestSubmissionStatus($locked, $to);

            OrderStatusEvent::create([
                'order_id' => $locked->getKey(),
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'reason_code' => $reasonCode,
                'note' => $note,
                'correlation_id' => $correlationId,
            ]);

            $this->synchronizeOperatorTasks($locked, $to, $correlationId, $context);

            $this->auditLogger->log(
                'campaign.status_changed',
                $actor,
                $locked,
                ['status' => $from->value],
                ['status' => $to->value, 'correlation_id' => $correlationId],
                $note ?? $reasonCode,
            );

            // Send a Telegram push notification to the customer after EVERY
            // successful transition (not just the ones triggered from the
            // admin UI). Centralising the notification here means internal
            // callers (recordTelegramSubmission, recordTelegramDecision,
            // payment service, scheduler) all notify consistently without
            // each caller needing to wire up the notifier manually.
            $this->notifyStatusChange($locked, $to, $note);

            return $locked->refresh();
        }, 3);
    }

    /**
     * Send a localized Telegram push notification describing the status
     * change to the order owner. Silently skipped when the order has no
     * user or the user has no Telegram chat id — the notifier itself
     * also guards against missing chat ids.
     */
    private function notifyStatusChange(Order $order, OrderStatus $to, ?string $note): void
    {
        $user = $order->user;
        if (! $user) {
            return;
        }

        $locale = $user->locale === 'fa' ? 'fa' : 'en';
        $statusLabel = $to->label($locale);
        $this->notifier->orderStatusChanged($order, $statusLabel, $note);
    }

    public function recordTelegramSubmission(
        Order $order,
        Admin $admin,
        string $externalAdId,
        ?string $externalAccountLabel = null,
        ?int $proofFileId = null,
        ?string $note = null,
    ): Order {
        $this->assertActiveAdmin($admin);
        $externalAdId = trim($externalAdId);

        if ($externalAdId === '') {
            throw new DomainException('Telegram external ad ID is required.');
        }

        return DB::transaction(function () use (
            $order,
            $admin,
            $externalAdId,
            $externalAccountLabel,
            $proofFileId,
            $note,
        ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::QueuedForTelegram) {
                throw new DomainException('Only a queued order can be recorded as submitted to Telegram.');
            }

            $revision = $this->lockedCurrentRevision($locked);
            $revision->forceFill(['is_locked' => true])->save();

            $submission = TelegramSubmission::query()->firstOrCreate([
                'campaign_revision_id' => $revision->getKey(),
                'external_ad_id' => $externalAdId,
            ], [
                'external_account_label' => $externalAccountLabel,
                'status' => TelegramSubmissionStatus::InReview,
                'proof_file_id' => $proofFileId,
                'submitted_by' => $admin->getKey(),
                'submitted_at' => now(),
            ]);

            if (! $submission->wasRecentlyCreated
                && ! in_array($submission->status, [TelegramSubmissionStatus::Submitted, TelegramSubmissionStatus::InReview], true)) {
                throw new DomainException('This Telegram submission is already resolved.');
            }

            if ($submission->status === TelegramSubmissionStatus::Submitted) {
                $submission->forceFill(['status' => TelegramSubmissionStatus::InReview])->save();
            }

            $this->auditLogger->log(
                'campaign.telegram_submission_recorded',
                $admin,
                $submission,
                [],
                ['external_ad_id' => $externalAdId, 'order_id' => $locked->getKey()],
                $note,
            );

            return $this->transition(
                $locked,
                OrderStatus::TelegramReview,
                $admin,
                'submitted_to_telegram',
                $note,
                context: ['telegram_submission_id' => $submission->getKey()],
            );
        }, 3);
    }

    public function recordTelegramDecision(
        Order $order,
        Admin $admin,
        bool $approved,
        ?string $rejectionReason = null,
        ?int $proofFileId = null,
    ): Order {
        $this->assertActiveAdmin($admin);

        if (! $approved && trim((string) $rejectionReason) === '') {
            throw new DomainException('Telegram rejection reason is required.');
        }

        return DB::transaction(function () use (
            $order,
            $admin,
            $approved,
            $rejectionReason,
            $proofFileId,
        ): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::TelegramReview) {
                throw new DomainException('Telegram decisions can only be recorded for an order under Telegram review.');
            }

            $revision = $this->lockedCurrentRevision($locked);
            $submission = TelegramSubmission::query()
                ->where('campaign_revision_id', $revision->getKey())
                ->latest('id')
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($submission->status, [TelegramSubmissionStatus::Submitted, TelegramSubmissionStatus::InReview], true)) {
                throw new DomainException('The latest Telegram submission is already resolved.');
            }

            $submission->forceFill([
                'status' => $approved ? TelegramSubmissionStatus::Approved : TelegramSubmissionStatus::Rejected,
                'rejection_reason' => $approved ? null : trim((string) $rejectionReason),
                'proof_file_id' => $proofFileId ?? $submission->proof_file_id,
                'resolved_at' => now(),
            ])->save();

            $target = $approved ? OrderStatus::TelegramApproved : OrderStatus::TelegramRejected;

            $this->auditLogger->log(
                $approved ? 'campaign.telegram_approved' : 'campaign.telegram_rejected',
                $admin,
                $submission,
                ['status' => TelegramSubmissionStatus::InReview->value],
                ['status' => $submission->status->value],
                $rejectionReason,
            );

            return $this->transition(
                $locked,
                $target,
                $admin,
                $approved ? 'telegram_approved' : 'telegram_rejected',
                $rejectionReason,
                context: ['telegram_submission_id' => $submission->getKey()],
            );
        }, 3);
    }

    private function assertAllowed(OrderStatus $from, OrderStatus $to): void
    {
        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true)) {
            throw new DomainException("Invalid campaign transition [{$from->value} -> {$to->value}].");
        }
    }

    private function assertActorCanTransition(
        Order $order,
        OrderStatus $from,
        OrderStatus $to,
        ?Model $actor,
    ): void {
        $this->assertActorBelongsToOperation($order, $actor);

        if ($actor instanceof User
            && ! in_array($to->value, self::USER_TRANSITIONS[$from->value] ?? [], true)) {
            throw new DomainException('This campaign transition is not available to the customer.');
        }
    }

    private function assertActorBelongsToOperation(Order $order, ?Model $actor): void
    {
        if ($actor === null) {
            return;
        }

        if (! $actor->exists) {
            throw new DomainException('Campaign transition actor must be persisted.');
        }

        if ($actor instanceof Admin) {
            $this->assertActiveAdmin($actor);

            return;
        }

        if ($actor instanceof User) {
            if ((int) $order->user_id !== (int) $actor->getKey()) {
                throw new DomainException('A user may only transition their own order.');
            }
        }
    }

    private function assertActiveAdmin(Admin $admin): void
    {
        if (! $admin->exists || ! $admin->is_active) {
            throw new DomainException('An active admin is required for this campaign operation.');
        }
    }

    private function assertDecisionReason(OrderStatus $to, ?string $reasonCode, ?string $note): void
    {
        $requiresReason = in_array($to, [
            OrderStatus::ChangesRequested,
            OrderStatus::CancelledBySupport,
            OrderStatus::TelegramRejected,
            OrderStatus::ManualAttention,
        ], true);

        if ($requiresReason && trim((string) $reasonCode) === '' && trim((string) $note) === '') {
            throw new DomainException("Campaign transition to [{$to->value}] requires a reason.");
        }
    }

    private function assertPrerequisites(Order $order, OrderStatus $to): void
    {
        if (in_array($to, [OrderStatus::SupportReview, OrderStatus::QueuedForTelegram, OrderStatus::Active], true)
            && $order->payment_status !== OrderPaymentStatus::Paid) {
            throw new DomainException("Order must be fully paid before entering [{$to->value}].");
        }

        if (in_array($to, [
            OrderStatus::QueuedForTelegram,
            OrderStatus::TelegramReview,
            OrderStatus::TelegramApproved,
            OrderStatus::TelegramRejected,
            OrderStatus::Scheduled,
            OrderStatus::Active,
        ], true)) {
            $revision = $this->lockedCurrentRevision($order);

            if ($to === OrderStatus::TelegramReview) {
                $this->assertSubmissionStatus($revision, [
                    TelegramSubmissionStatus::Submitted,
                    TelegramSubmissionStatus::InReview,
                ]);
            }

            if ($to === OrderStatus::TelegramApproved) {
                $this->assertSubmissionStatus($revision, [TelegramSubmissionStatus::Approved]);
            }

            if ($to === OrderStatus::TelegramRejected) {
                $this->assertSubmissionStatus($revision, [TelegramSubmissionStatus::Rejected]);
            }

            if ($to === OrderStatus::Active) {
                $this->assertSubmissionStatus($revision, [
                    TelegramSubmissionStatus::Approved,
                    TelegramSubmissionStatus::Active,
                    TelegramSubmissionStatus::Paused,
                ]);
            }
        }

        if ($to === OrderStatus::Scheduled && $order->planned_start_at === null) {
            throw new DomainException('A planned start date is required before scheduling a campaign.');
        }
    }

    private function lockedCurrentRevision(Order $order): CampaignRevision
    {
        if ($order->current_revision_id === null) {
            throw new DomainException('Order does not have a current campaign revision.');
        }

        $revision = CampaignRevision::query()
            ->whereKey($order->current_revision_id)
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if ($revision === null) {
            throw new DomainException('The current campaign revision does not belong to this order.');
        }

        return $revision;
    }

    /** @param list<TelegramSubmissionStatus> $statuses */
    private function assertSubmissionStatus(CampaignRevision $revision, array $statuses): void
    {
        $submission = TelegramSubmission::query()
            ->where('campaign_revision_id', $revision->getKey())
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($submission === null || ! in_array($submission->status, $statuses, true)) {
            throw new DomainException('The latest Telegram submission does not satisfy this transition.');
        }

        if (trim((string) $submission->external_ad_id) === '') {
            throw new DomainException('The Telegram submission is missing its external ad ID.');
        }
    }

    private function synchronizeLatestSubmissionStatus(Order $order, OrderStatus $to): void
    {
        $submissionStatus = match ($to) {
            OrderStatus::Active => TelegramSubmissionStatus::Active,
            OrderStatus::Paused => TelegramSubmissionStatus::Paused,
            OrderStatus::Completed => TelegramSubmissionStatus::Completed,
            default => null,
        };

        if ($submissionStatus === null || $order->current_revision_id === null) {
            return;
        }

        $submission = TelegramSubmission::query()
            ->where('campaign_revision_id', $order->current_revision_id)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($submission === null) {
            return;
        }

        $allowedCurrentStatuses = match ($submissionStatus) {
            TelegramSubmissionStatus::Active => [
                TelegramSubmissionStatus::Approved,
                TelegramSubmissionStatus::Active,
                TelegramSubmissionStatus::Paused,
            ],
            TelegramSubmissionStatus::Paused => [
                TelegramSubmissionStatus::Approved,
                TelegramSubmissionStatus::Active,
                TelegramSubmissionStatus::Paused,
            ],
            TelegramSubmissionStatus::Completed => [
                TelegramSubmissionStatus::Approved,
                TelegramSubmissionStatus::Active,
                TelegramSubmissionStatus::Paused,
                TelegramSubmissionStatus::Completed,
            ],
            default => [],
        };

        if (! in_array($submission->status, $allowedCurrentStatuses, true)) {
            return;
        }

        $submission->forceFill([
            'status' => $submissionStatus,
            'resolved_at' => $submissionStatus === TelegramSubmissionStatus::Completed ? now() : $submission->resolved_at,
        ])->save();
    }

    /** @param array<string, mixed> $context */
    private function synchronizeOperatorTasks(
        Order $order,
        OrderStatus $to,
        string $correlationId,
        array $context,
    ): void {
        $taskContext = array_merge($context, [
            'correlation_id' => $correlationId,
            'trigger_status' => $to->value,
        ]);

        match ($to) {
            OrderStatus::QueuedForTelegram => $this->openTask($order, 'submit_telegram_ad', $taskContext),
            OrderStatus::TelegramReview => $this->replaceTask(
                $order,
                ['submit_telegram_ad'],
                'sync_telegram_review',
                $taskContext,
            ),
            OrderStatus::TelegramApproved => $this->completeTasks(
                $order,
                ['sync_telegram_review'],
            ),
            OrderStatus::TelegramRejected => $this->replaceTask(
                $order,
                ['sync_telegram_review'],
                'reconcile_telegram_rejection',
                array_merge($taskContext, [
                    'required_action' => 'Record final spend and move eligible unused value to restricted ad credit.',
                ]),
            ),
            OrderStatus::Scheduled => $this->openTask(
                $order,
                'activate_telegram_ad',
                $taskContext,
                $order->planned_start_at,
            ),
            OrderStatus::PauseRequested => $this->openTask($order, 'pause_telegram_ad', $taskContext),
            OrderStatus::Paused => $this->completeTasks($order, ['pause_telegram_ad']),
            OrderStatus::ResumeRequested => $this->openTask($order, 'resume_telegram_ad', $taskContext),
            OrderStatus::Active => $this->activateTasks($order, $taskContext),
            OrderStatus::Completed => $this->prepareCompletionReconciliation($order, $taskContext),
            OrderStatus::ManualAttention => $this->openTask($order, 'manual_attention', $taskContext),
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    private function openTask(
        Order $order,
        string $type,
        array $context,
        mixed $dueAt = null,
    ): OperatorTask {
        return OperatorTask::query()->firstOrCreate([
            'order_id' => $order->getKey(),
            'type' => $type,
            'status' => 'open',
        ], [
            'due_at' => $dueAt,
            'context' => $context,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function prepareCompletionReconciliation(Order $order, array $context): void
    {
        $this->completeAllOpenTasks($order);

        $hasActiveHold = DB::table('fund_holds')
            ->where('order_id', $order->getKey())
            ->where('status', 'active')
            ->exists();
        if ($order->payment_status !== OrderPaymentStatus::Paid || ! $hasActiveHold) {
            return;
        }

        $this->openTask($order, 'reconcile_completed_campaign', array_merge($context, [
            'required_action' => 'Record final Telegram spend and move eligible unused value to restricted ad credit.',
        ]));
    }

    /** @param list<string> $completedTypes @param array<string, mixed> $context */
    private function replaceTask(Order $order, array $completedTypes, string $newType, array $context): void
    {
        $this->completeTasks($order, $completedTypes);
        $this->openTask($order, $newType, $context);
    }

    /** @param list<string> $types */
    private function completeTasks(Order $order, array $types): void
    {
        OperatorTask::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'open')
            ->whereIn('type', $types)
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $context */
    private function activateTasks(Order $order, array $context): void
    {
        $this->completeTasks($order, ['activate_telegram_ad', 'resume_telegram_ad', 'pause_telegram_ad']);
        $this->openTask($order, 'sync_campaign_metrics', $context);
    }

    private function completeAllOpenTasks(Order $order): void
    {
        OperatorTask::query()
            ->where('order_id', $order->getKey())
            ->where('status', 'open')
            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
    }
}
