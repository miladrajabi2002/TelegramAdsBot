<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the new ad-target fields introduced by the wizard redesign:
 *
 *   - daily_view_limit_per_user  : the "محدودیت بازدید روزانه برای هر کاربر"
 *                                  4-button selector (1..4) shown for every
 *                                  placement type (channels / bots / search).
 *                                  Replaces the old `frequency_cap` UX which
 *                                  was a 1..5 dropdown buried in step 4.
 *                                  Kept as a separate column so historical
 *                                  rows keep their old `frequency_cap` value
 *                                  untouched.
 *
 *   - ad_media_path / ad_media_type / ad_media_disk :
 *                                  Optional image or video that the advertiser
 *                                  attaches when placement_type = channel_posts
 *                                  (the "افزودن تصویر یا ویدیو" field). Stored on
 *                                  the same private disk as KYC documents.
 *
 *   - search_keywords             : JSON list of strings typed by the user
 *                                  when placement_type = search_results
 *                                  (the "جستجوی هدف" tag input). Each keyword
 *                                  must be at least 4 characters.
 *
 * The destination_type column is left in place for backward compatibility with
 * existing rows — the wizard simply no longer collects it (it is now derived
 * from placement_type at runtime: channel_posts→channel, bot_messages→bot,
 * search_results→channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_revisions', function (Blueprint $table) {
            if (! Schema::hasColumn('campaign_revisions', 'daily_view_limit_per_user')) {
                $table->unsignedTinyInteger('daily_view_limit_per_user')->default(1)->after('frequency_cap');
            }
            if (! Schema::hasColumn('campaign_revisions', 'ad_media_path')) {
                $table->string('ad_media_path', 512)->nullable()->after('language');
            }
            if (! Schema::hasColumn('campaign_revisions', 'ad_media_type')) {
                $table->string('ad_media_type', 20)->nullable()->after('ad_media_path');
            }
            if (! Schema::hasColumn('campaign_revisions', 'ad_media_disk')) {
                $table->string('ad_media_disk', 40)->default('local')->after('ad_media_type');
            }
            if (! Schema::hasColumn('campaign_revisions', 'search_keywords')) {
                $table->json('search_keywords')->nullable()->after('ad_media_disk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_revisions', function (Blueprint $table) {
            $columns = ['daily_view_limit_per_user', 'ad_media_path', 'ad_media_type', 'ad_media_disk', 'search_keywords'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('campaign_revisions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
