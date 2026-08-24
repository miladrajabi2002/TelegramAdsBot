<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('campaign_targets') || ! Schema::hasTable('suggested_channels')) {
            return;
        }

        // A target linked to the admin-curated catalogue has already passed
        // the catalogue review. Repair existing snapshots so old and new
        // orders display the same explicit approval state.
        DB::table('campaign_targets')
            ->whereNotNull('suggested_channel_id')
            ->whereIn('validation_status', ['pending', 'eligible', 'unverified'])
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('suggested_channels')
                    ->whereColumn('suggested_channels.id', 'campaign_targets.suggested_channel_id')
                    ->where('suggested_channels.is_active', true);
            })
            ->update([
                'validation_status' => 'approved',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Approval is an auditable business fact and cannot be reconstructed
        // safely as pending/eligible during rollback.
    }
};
