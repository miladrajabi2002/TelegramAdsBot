<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a `magic_token` column to the `users` table.
     *
     * Why: Telegram's `Telegram.WebApp.initData` is only populated when the
     * Mini App is opened via an inline web_app button OR a configured Mini App
     * menu button in BotFather. If the user opens the URL directly (typed
     * in a chat, opened from a bookmark, or in a Telegram client that doesn't
     * inject initData reliably), `initData` is empty and the Mini App shows
     * the "Telegram sign-in data is unavailable" error forever — with no
     * recovery path except reopening via the bot.
     *
     * To solve this, we generate a personal long-lived magic_token per user.
     * The bot includes `?t=<token>` in the inline-button URL, so even when
     * initData is unavailable the Mini App can authenticate via the token.
     *
     * The token is rotated whenever the user clicks /start again (via
     * `User::rotateMagicToken()`), so a leaked URL only grants access until
     * the user re-engages with the bot.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('magic_token', 80)->unique()->nullable()->after('locale_set_at');
        });

        // Backfill existing users with a token so the new flow works for them
        // immediately — no need for them to click /start again.
        \App\Models\User::whereNull('magic_token')->each(function (\App\Models\User $user): void {
            $user->magic_token = \Illuminate\Support\Str::random(64);
            $user->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('magic_token');
        });
    }
};
