<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes `ad_media_disk` nullable on `campaign_revisions`.
 *
 * The column was originally defined as NOT NULL with a default of 'local'
 * — but the application writes NULL whenever an advertiser submits a
 * campaign WITHOUT an attached image/video (most text-only campaigns).
 * That triggered `SQLSTATE[23000]: Column 'ad_media_disk' cannot be null`
 * on every media-less submission. Making the column nullable aligns the
 * schema with the application's intent: no media ⇒ no disk.
 *
 * Laravel's schema builder handles the platform-specific ALTER/rebuild. Raw
 * MySQL `MODIFY COLUMN` SQL breaks the SQLite in-memory test database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaign_revisions', 'ad_media_disk')) {
            return;
        }

        Schema::table('campaign_revisions', function (Blueprint $table): void {
            $table->string('ad_media_disk', 40)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('campaign_revisions', 'ad_media_disk')) {
            return;
        }

        Schema::table('campaign_revisions', function (Blueprint $table): void {
            $table->string('ad_media_disk', 40)->nullable(false)->default('local')->change();
        });
    }
};
