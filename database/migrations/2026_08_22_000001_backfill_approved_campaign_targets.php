<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasTable('campaign_targets')) {
            return;
        }

        // Older orders may already have passed support review while their
        // current-revision targets were still left as pending/eligible. Once an
        // order has entered Telegram/delivery stages, support approval has
        // necessarily happened, so those target rows should be approved too.
        $postSupportStatuses = [
            'queued_for_telegram',
            'telegram_review',
            'telegram_approved',
            'scheduled',
            'active',
            'pause_requested',
            'paused',
            'resume_requested',
            'telegram_rejected',
            'completed',
        ];

        DB::table('campaign_targets')
            ->whereIn('validation_status', ['pending', 'eligible'])
            ->whereExists(function ($query) use ($postSupportStatuses): void {
                $query->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.current_revision_id', 'campaign_targets.campaign_revision_id')
                    ->whereIn('orders.status', $postSupportStatuses);
            })
            ->update([
                'validation_status' => 'approved',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data repair is intentionally irreversible. Reverting approved targets
        // to pending would lose the fact that support approval already occurred.
    }
};
