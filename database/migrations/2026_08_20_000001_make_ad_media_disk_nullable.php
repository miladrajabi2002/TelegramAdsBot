<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
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
 * Raw SQL is used (instead of $table->string(...)->nullable()->change())
 * because doctrine/dbal is not installed in this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('campaign_revisions', 'ad_media_disk')) {
            return;
        }

        DB::statement('ALTER TABLE `campaign_revisions` MODIFY COLUMN `ad_media_disk` VARCHAR(40) NULL DEFAULT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('campaign_revisions', 'ad_media_disk')) {
            return;
        }

        DB::statement("ALTER TABLE `campaign_revisions` MODIFY COLUMN `ad_media_disk` VARCHAR(40) NOT NULL DEFAULT 'local'");
    }
};
