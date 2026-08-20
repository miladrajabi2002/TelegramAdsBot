<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older builds persisted Telegram file URLs that contain the bot token.
        // The new code never reads or generates client-visible file URLs, so
        // remove all known legacy copies from persistent storage.
        DB::table('users')
            ->where('photo_url', 'like', 'https://api.telegram.org/file/bot%')
            ->update(['photo_url' => null]);

        if (Schema::hasTable('suggested_channels') && Schema::hasColumn('suggested_channels', 'avatar_url')) {
            DB::table('suggested_channels')
                ->where('avatar_url', 'like', 'https://api.telegram.org/file/bot%')
                ->update(['avatar_url' => null]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: token-bearing URLs must not be restored.
    }
};
