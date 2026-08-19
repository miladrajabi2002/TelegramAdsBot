<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use Illuminate\Console\Command;

/**
 * Diagnose payment intents stuck in `manual_review`.
 *
 * Lists the most recent manual-review intents, prints the stored
 * provider_response (so you can see EXACTLY what ZarinPay/NOWPayments
 * returned vs what we expected), and shows the corresponding audit log
 * entry that explains WHY the mismatch was flagged.
 *
 * Usage:
 *   php artisan payments:diagnose
 *   php artisan payments:diagnose --intent=123
 *   php artisan payments:diagnose --limit=20
 */
class DiagnosePaymentReview extends Command
{
    protected $signature = 'payments:diagnose
                            {--intent= : Specific payment_intent ID to inspect}
                            {--limit=10 : How many recent manual-review intents to list}';

    protected $description = 'Diagnose payment intents held for manual review — shows the gateway response, the mismatch reason, and the audit log trail.';

    public function handle(): int
    {
        $intentId = $this->option('intent');
        $limit = max(1, (int) $this->option('limit', 10));

        if ($intentId !== null) {
            $intent = PaymentIntent::with(['user', 'order', 'attempts'])->find((int) $intentId);
            if (! $intent) {
                $this->error("PaymentIntent #{$intentId} not found.");

                return self::FAILURE;
            }
            $this->printIntent($intent);

            return self::SUCCESS;
        }

        $intents = PaymentIntent::query()
            ->where('status', 'manual_review')
            ->with(['user', 'order', 'attempts'])
            ->latest()
            ->limit($limit)
            ->get();

        if ($intents->isEmpty()) {
            $this->info('No payment intents are currently in manual_review.');

            // Also show the recent history (any status) so the operator
            // can spot-check the most recent payments.
            $recent = PaymentIntent::query()
                ->with(['user', 'attempts'])
                ->latest()
                ->limit(5)
                ->get();
            if ($recent->isNotEmpty()) {
                $this->newLine();
                $this->line('Recent payments (any status):');
                $this->printTable($recent);
            }

            return self::SUCCESS;
        }

        $this->info("Found {$intents->count()} payment intent(s) in manual_review:");
        $this->newLine();
        $this->printTable($intents);

        $this->newLine();
        $this->line('Run `php artisan payments:diagnose --intent=<id>` for full details on any one.');

        return self::SUCCESS;
    }

    private function printTable($intents): void
    {
        $rows = [];
        foreach ($intents as $intent) {
            $rows[] = [
                (string) $intent->id,
                (string) $intent->provider,
                (string) $intent->merchant_reference,
                // purpose is cast to PaymentPurpose enum — use ->value
                // so Symfony Table can render it as a string cell.
                $intent->purpose?->value ?? '—',
                (string) ($intent->amount_minor.' '.$intent->currency),
                // status is cast to PaymentStatus enum — same reason.
                $intent->status?->value ?? '—',
                (string) ($intent->user?->display_name ?? '—'),
                (string) ($intent->order?->public_id ?? '—'),
                (string) ($intent->created_at?->format('Y-m-d H:i') ?? '—'),
            ];
        }

        $this->table(
            ['ID', 'Provider', 'Merchant Ref', 'Purpose', 'Amount', 'Status', 'User', 'Order', 'Created'],
            $rows,
        );
    }

    private function printIntent(PaymentIntent $intent): void
    {
        $this->newLine();
        $this->info('PaymentIntent #'.$intent->id);
        $this->line(str_repeat('─', 60));
        $this->line('Provider:          '.$intent->provider);
        $this->line('Merchant Ref:      '.$intent->merchant_reference);
        // status/purpose are enums — must use ->value because enums don't
        // implement __toString() and string interpolation would throw.
        $this->line('Status:            '.($intent->status?->value ?? '—'));
        $this->line('Purpose:           '.($intent->purpose?->value ?? '—'));
        $this->line('Amount:            '.number_format((int) $intent->amount_minor).' '.$intent->currency);
        $this->line('User:              '.($intent->user?->display_name ?? '—').' (ID '.$intent->user_id.')');
        $this->line('Order:             '.($intent->order?->public_id ?? '— (wallet top-up)'));
        $this->line('Created:           '.$intent->created_at?->toDateTimeString());
        $this->line('Verified:          '.($intent->verified_at?->toDateTimeString() ?? '—'));
        $this->line('Metadata:          '.json_encode($intent->metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->newLine();

        $this->info('Payment Attempts:');
        foreach ($intent->attempts as $attempt) {
            $this->line(str_repeat('─', 60));
            $this->line('  Attempt #'.$attempt->id);
            $this->line('  Authority:        '.($attempt->authority ?? '—'));
            $this->line('  Provider Ref:     '.($attempt->provider_reference ?? '—'));
            $this->line('  Verify Code:      '.($attempt->verify_code ?? '—'));
            $this->line('  Verified At:      '.($attempt->verified_at?->toDateTimeString() ?? '—'));
            $this->line('  Redirect URL:     '.($attempt->redirect_url ?? '—'));
            $this->line('  Provider Response:');
            $response = $attempt->provider_response;
            if (is_string($response)) {
                $decoded = json_decode($response, true);
                $response = is_array($decoded) ? $decoded : $response;
            }
            $pretty = is_array($response)
                ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : (string) $response;
            $this->line('    '.str_replace("\n", "\n    ", $pretty));
        }

        $this->newLine();
        $this->info('Audit Log Trail:');
        $logs = AuditLog::query()
            ->where(function ($query) use ($intent): void {
                $query->where('subject_type', $intent->getMorphClass())
                    ->where('subject_id', $intent->getKey());
            })
            ->orWhere(function ($query) use ($intent): void {
                $query->where('actor_type', $intent->getMorphClass())
                    ->where('actor_id', $intent->getKey());
            })
            ->latest()
            ->limit(20)
            ->get();

        if ($logs->isEmpty()) {
            $this->line('  (no audit entries)');
        } else {
            foreach ($logs as $log) {
                $this->line('  ['.$log->created_at?->toDateTimeString()."] {$log->action}");
                if ($log->reason) {
                    $this->line("    reason: {$log->reason}");
                }
                $after = $log->after_redacted;
                if (is_string($after)) {
                    $after = json_decode($after, true) ?? $after;
                }
                if (is_array($after) && $after !== []) {
                    $this->line('    after: '.json_encode($after, JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
}
