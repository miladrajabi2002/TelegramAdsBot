<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CampaignTarget;
use App\Models\Order;
use App\Services\AuditLogger;
use App\Services\CampaignTransitionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin workflow actions kept separate from the large order controller so the
 * status machine remains the single source of truth while target review gets
 * an explicit, auditable action.
 */
class OrderWorkflowController extends Controller
{
    public function transition(
        Request $request,
        Order $order,
        CampaignTransitionService $service,
        AuditLogger $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'to_status' => ['required', Rule::enum(OrderStatus::class)],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $targetStatus = OrderStatus::from($data['to_status']);
        $isAdminManualOverride = ($data['reason_code'] ?? null) === 'manual_admin_override';

        if (in_array($targetStatus, [
            OrderStatus::ChangesRequested,
            OrderStatus::CancelledBySupport,
            OrderStatus::ManualAttention,
        ], true)
            && trim((string) ($data['reason_code'] ?? '')) === ''
            && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'note' => 'برای این تصمیم، دلیل یا یادداشت الزامی است.',
            ]);
        }

        // Telegram rejection has two mutually-exclusive paths:
        //   1) return to the customer for another correction, keeping the hold; or
        //   2) finalize financial reconciliation and release/post the hold.
        // Once reconciliation has been completed, reusing the same paid order for
        // another Telegram attempt would no longer be financially sound.
        $cancelOpenReconciliation = false;
        if (! $isAdminManualOverride
            && $order->status === OrderStatus::TelegramRejected
            && $targetStatus === OrderStatus::ChangesRequested) {
            $reconciliation = $order->operatorTasks()
                ->where('type', 'reconcile_telegram_rejection')
                ->latest('id')
                ->first();

            if ($reconciliation?->status === 'completed') {
                throw ValidationException::withMessages([
                    'status' => 'تطبیق مالی این رد Telegram قبلاً نهایی شده است؛ این سفارش دیگر نباید با همان بودجه برای اصلاح مجدد ارسال شود.',
                ]);
            }
            $cancelOpenReconciliation = $reconciliation?->status === 'open';
        }

        // The first support approval is also the approval point for campaign
        // targets. Pending catalogue/manual targets are promoted to `approved`
        // atomically with the order transition. Explicitly rejected/ineligible
        // targets still block the transition and must be resolved first.
        $targetRevisionId = null;
        if ($targetStatus === OrderStatus::QueuedForTelegram && ! $isAdminManualOverride) {
            $order->loadMissing('currentRevision');
            $targetRevisionId = $order->current_revision_id;

            if ($targetRevisionId === null) {
                throw ValidationException::withMessages([
                    'targets' => 'نسخه فعلی سفارش پیدا نشد.',
                ]);
            }
        }

        try {
            DB::transaction(function () use (
                $order,
                $targetStatus,
                $service,
                $audit,
                $targetRevisionId,
                $data,
            ): void {
                $admin = auth('admin')->user();

                if ($targetStatus === OrderStatus::QueuedForTelegram && $targetRevisionId !== null) {
                    // Re-read and lock the target rows inside the same DB
                    // transaction as the status transition. This prevents a
                    // concurrent target decision from racing support approval.
                    $targets = CampaignTarget::query()
                        ->where('campaign_revision_id', $targetRevisionId)
                        ->lockForUpdate()
                        ->get();

                    if ($targets->isEmpty()) {
                        throw ValidationException::withMessages([
                            'targets' => 'حداقل یک کانال یا ربات هدف باید ثبت شده باشد.',
                        ]);
                    }

                    $autoApprovable = ['pending', 'eligible', 'approved'];
                    $blocking = $targets->filter(
                        fn ($target) => ! in_array((string) $target->validation_status, $autoApprovable, true),
                    );

                    if ($blocking->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'targets' => 'یک یا چند کانال/ربات هدف رد یا غیرمجاز است. ابتدا همان موارد را تعیین تکلیف کنید.',
                        ]);
                    }

                    foreach ($targets->filter(
                        fn ($target) => in_array((string) $target->validation_status, ['pending', 'eligible'], true),
                    ) as $target) {
                        $before = ['validation_status' => (string) $target->validation_status];
                        $target->forceFill(['validation_status' => 'approved'])->save();

                        $audit->log(
                            'campaign.target_auto_approved',
                            $admin,
                            $target,
                            before: $before,
                            after: [
                                'validation_status' => 'approved',
                                'order_id' => $order->getKey(),
                            ],
                            reason: 'support_approval',
                        );
                    }
                }

                $service->transition(
                    $order,
                    $targetStatus,
                    $admin,
                    $data['reason_code'] ?? null,
                    $data['note'] ?? null,
                );
            }, 3);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        if ($cancelOpenReconciliation) {
            $task = $order->operatorTasks()
                ->where('type', 'reconcile_telegram_rejection')
                ->where('status', 'open')
                ->latest('id')
                ->first();
            if ($task) {
                $context = is_array($task->context) ? $task->context : [];
                $task->forceFill([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'context' => array_merge($context, [
                        'cancelled_reason' => 'customer_correction_requested',
                        'cancelled_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }
        }

        return back()->with('success', 'وضعیت سفارش با ثبت کامل تاریخچه تغییر کرد.');
    }

    public function targetDecision(
        Request $request,
        Order $order,
        CampaignTarget $target,
        AuditLogger $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ]);

        $order->loadMissing('currentRevision');
        abort_unless(
            $order->current_revision_id !== null
            && (int) $target->campaign_revision_id === (int) $order->current_revision_id,
            404,
        );

        if (! in_array($order->status, [OrderStatus::SupportReview, OrderStatus::ChangesRequested], true)) {
            throw ValidationException::withMessages([
                'target' => 'وضعیت کانال/ربات هدف فقط قبل از ارسال سفارش به Telegram قابل تغییر است.',
            ]);
        }

        $before = ['validation_status' => $target->validation_status];
        $target->update(['validation_status' => $data['decision']]);

        $audit->log(
            'campaign.target_reviewed',
            auth('admin')->user(),
            $target,
            before: $before,
            after: [
                'validation_status' => $target->validation_status,
                'order_id' => $order->getKey(),
            ],
            reason: $data['note'] ?? null,
        );

        return back()->with(
            'success',
            $data['decision'] === 'approved'
                ? 'کانال/ربات هدف تأیید شد.'
                : 'کانال/ربات هدف رد شد. در صورت نیاز سفارش را برای اصلاح به کاربر برگردانید.',
        );
    }
}
