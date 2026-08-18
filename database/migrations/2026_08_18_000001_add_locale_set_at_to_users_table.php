<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a `locale_set_at` timestamp to the `users` table so the bot can
     * distinguish between "locale inferred from Telegram's language_code"
     * (a hint, not an explicit choice) and "user has explicitly chosen a
     * language from the inline picker".
     *
     * Without this column, the bot would have to either always re-ask the
     * language on every /start (annoying) or trust the language_code hint
     * forever (ignores the user's actual choice). With this column the bot
     * asks exactly once and remembers the choice forever.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('locale_set_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale_set_at');
        });
    }
};
