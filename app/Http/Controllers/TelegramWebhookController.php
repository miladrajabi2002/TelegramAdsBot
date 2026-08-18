<?php

namespace App\Http\Controllers;

use App\Jobs\SendTelegramMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 262_144;

    /** Callback-data namespace used to route inline button presses. */
    private const CB_LANG_PREFIX = 'lang:';
    private const CB_OPEN_APP = 'open_app';
    private const CB_SUPPORT = 'support';
    private const CB_PAYSUPPORT = 'paysupport';
    private const CB_HELP = 'help';
    private const CB_CHANGE_LANG = 'change_lang';

    public function __invoke(Request $request): JsonResponse
    {
        $configuredSecret = (string) config('services.telegram.webhook_secret');
        $receivedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if($configuredSecret === '' || ! hash_equals($configuredSecret, $receivedSecret), 403);
        abort_if(strlen($request->getContent()) > self::MAX_BODY_BYTES, 413);

        $updateId = $request->integer('update_id');
        abort_if($updateId < 1, 422);

        DB::table('telegram_webhook_events')->insertOrIgnore([
            'update_id' => $updateId,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::table('telegram_webhook_events')->where('update_id', $updateId)->whereNotNull('processed_at')->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $lock = Cache::lock('telegram-webhook-update:'.$updateId, 30);
        abort_unless($lock->get(), 503);

        try {
            if (DB::table('telegram_webhook_events')->where('update_id', $updateId)->whereNotNull('processed_at')->exists()) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }

            DB::table('telegram_webhook_events')->where('update_id', $updateId)->update([
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            $payload = $request->input();

            // Route to the appropriate handler. We support both inline-button
            // presses (callback_query) and plain messages. Both paths share
            // the same idempotency lock so an update is never processed twice.
            if (isset($payload['callback_query']) && is_array($payload['callback_query'])) {
                $this->processCallbackQuery($payload['callback_query']);
            } else {
                $this->processMessage($payload['message'] ?? null);
            }

            DB::table('telegram_webhook_events')->where('update_id', $updateId)->update([
                'processed_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return response()->json(['ok' => true]);
        } catch (Throwable $exception) {
            DB::table('telegram_webhook_events')->where('update_id', $updateId)->update([
                'last_error' => Str::limit($exception->getMessage(), 1000, ''),
                'updated_at' => now(),
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function processMessage(mixed $message): void
    {
        if (! is_array($message)) {
            return;
        }

        $telegramId = data_get($message, 'from.id');
        $contactUserId = data_get($message, 'contact.user_id');
        $phone = data_get($message, 'contact.phone_number');
        $telegramUserRecord = null;

        if ($telegramId) {
            $displayName = trim((string) data_get($message, 'from.first_name').' '.(string) data_get($message, 'from.last_name'));
            $telegramUserRecord = User::firstOrNew(['telegram_user_id' => $telegramId]);
            $telegramUserRecord->fill([
                'telegram_username' => data_get($message, 'from.username'),
                'first_name' => data_get($message, 'from.first_name'),
                'last_name' => data_get($message, 'from.last_name'),
                'display_name' => $displayName !== '' ? $displayName : 'Telegram user',
                'last_seen_at' => now(),
            ]);
            // Seed the locale from the Telegram client's language_code on the
            // very first contact. We DON'T set locale_set_at — that timestamp
            // is only written when the user clicks an inline language button.
            // This way, /start asks the user ONCE for their language choice,
            // and on subsequent /start commands the choice is honoured forever.
            if (! $telegramUserRecord->exists) {
                $telegramUserRecord->locale = str_starts_with((string) data_get($message, 'from.language_code', ''), 'fa') ? 'fa' : 'en';
            }
            $telegramUserRecord->save();
        }

        $text = trim((string) data_get($message, 'text', ''));
        $command = str_starts_with($text, '/')
            ? strtolower((string) preg_replace('/@[^\s]+$/', '', explode(' ', $text, 2)[0]))
            : null;

        if ($telegramId && $command === '/start') {
            $this->sendStartMenu((int) $telegramId, $telegramUserRecord);
        } elseif ($telegramId && $command === '/lang') {
            // Manual re-selection of language — keeps the "ask once" UX intact
            // while still giving the user a way to switch later.
            $this->sendLanguagePicker((int) $telegramId, $telegramUserRecord);
        } elseif ($telegramId && in_array($command, ['/terms', '/privacy'], true)) {
            $routeName = $command === '/terms' ? 'legal.terms' : 'legal.privacy';
            $label = $command === '/terms' ? 'شرایط استفاده / Terms' : 'حریم خصوصی / Privacy';
            SendTelegramMessage::dispatch(
                (int) $telegramId,
                '<b>'.$label.'</b>'."\n".
                '<a href="'.e(route($routeName, ['lang' => 'fa'])).'">نسخه فارسی</a>'."\n".
                '<a href="'.e(route($routeName, ['lang' => 'en'])).'">English version</a>',
            );
        } elseif ($telegramId && $command === '/help') {
            $this->sendHelpMessage((int) $telegramId, $telegramUserRecord);
        } elseif ($telegramId && in_array($command, ['/support', '/paysupport'], true)) {
            $this->sendSupportMessage((int) $telegramId, $telegramUserRecord, $command === '/paysupport');
        }

        // Phone-number sharing via the keyboard fallback (kept for legacy
        // clients that don't support inline web_app buttons).
        if ($telegramId && $contactUserId && (string) $telegramId === (string) $contactUserId && $phone) {
            $user = $telegramUserRecord ?? User::where('telegram_user_id', $telegramId)->first();
            if ($user) {
                $user->update([
                    'phone' => '+'.ltrim(preg_replace('/\D/', '', (string) $phone) ?? '', '+'),
                    'phone_verified_at' => now(),
                ]);
                $isFa = $user->locale === 'fa';
                SendTelegramMessage::dispatch(
                    (int) $telegramId,
                    $isFa
                        ? 'شماره همراه شما با موفقیت تأیید شد. اکنون می‌توانید احراز هویت پرداخت ریالی را ادامه دهید.'
                        : 'Your phone number was verified. You can now continue with rial KYC.',
                );
            }
        }
    }

    private function processCallbackQuery(mixed $callbackQuery): void
    {
        $telegramId = data_get($callbackQuery, 'from.id');
        $data = (string) data_get($callbackQuery, 'data', '');
        $messageId = data_get($callbackQuery, 'message.message_id');
        $chatId = data_get($callbackQuery, 'message.chat.id') ?? $telegramId;

        if (! $telegramId || $data === '') {
            return;
        }

        $user = User::firstOrNew(['telegram_user_id' => $telegramId]);
        if (! $user->exists) {
            $displayName = trim((string) data_get($callbackQuery, 'from.first_name').' '.(string) data_get($callbackQuery, 'from.last_name'));
            $user->fill([
                'telegram_username' => data_get($callbackQuery, 'from.username'),
                'first_name' => data_get($callbackQuery, 'from.first_name'),
                'last_name' => data_get($callbackQuery, 'from.last_name'),
                'display_name' => $displayName !== '' ? $displayName : 'Telegram user',
                'locale' => str_starts_with((string) data_get($callbackQuery, 'from.language_code', ''), 'fa') ? 'fa' : 'en',
                'last_seen_at' => now(),
            ]);
            $user->save();
        } else {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        $isFa = $user->locale === 'fa';
        $toast = match (true) {
            str_starts_with($data, self::CB_LANG_PREFIX) => $this->applyLanguage($user, substr($data, strlen(self::CB_LANG_PREFIX))),
            $data === self::CB_CHANGE_LANG => $isFa ? 'زبان را انتخاب کنید' : 'Choose your language',
            $data === self::CB_OPEN_APP => $isFa ? 'دکمه «ورود به مینی‌اپ» را بزنید' : 'Tap “Open Mini App”',
            $data === self::CB_SUPPORT => $isFa ? 'بخش پشتیبانی باز می‌شود' : 'Opening support',
            $data === self::CB_PAYSUPPORT => $isFa ? 'پشتیبانی پرداخت' : 'Payment support',
            $data === self::CB_HELP => $isFa ? 'راهنما' : 'Help',
            default => '',
        };

        // Always answer the callback to clear the spinner — even for unknown data.
        SendTelegramMessage::dispatch((int) $chatId, '', [
            'callback_query_id' => (string) data_get($callbackQuery, 'id'),
            'answer_text' => $toast,
            'answer_show_alert' => false,
            'edit_message_id' => is_numeric($messageId) ? (int) $messageId : null,
        ]);

        // After answering, decide if we also need to send/edit a message.
        if (str_starts_with($data, self::CB_LANG_PREFIX) || $data === self::CB_CHANGE_LANG) {
            // Language was just changed (or user asked to change it): show the
            // main menu in the freshly selected language.
            $this->sendStartMenu((int) $chatId, $user);
        } elseif ($data === self::CB_HELP) {
            $this->sendHelpMessage((int) $chatId, $user);
        } elseif ($data === self::CB_SUPPORT || $data === self::CB_PAYSUPPORT) {
            $this->sendSupportMessage((int) $chatId, $user, $data === self::CB_PAYSUPPORT);
        }
    }

    private function applyLanguage(User $user, string $locale): string
    {
        if (! in_array($locale, ['fa', 'en'], true)) {
            return 'invalid';
        }

        $user->locale = $locale;
        $user->locale_set_at = now();
        $user->save();

        return $locale === 'fa' ? 'زبان فارسی ذخیره شد ✅' : 'Language saved ✅';
    }

    /**
     * Build and dispatch the main /start menu with INLINE buttons.
     *
     * If the user has already chosen a language (locale_set_at is set), we
     * jump straight to the menu. Otherwise we show the language picker
     * first — once. This is the "ask once, never again" UX the user asked for.
     */
    private function sendStartMenu(int $chatId, ?User $user): void
    {
        $user ??= User::where('telegram_user_id', $chatId)->first();

        // First-contact guard: only ask the language once. The locale_set_at
        // timestamp is set ONLY when the user clicks an inline button — never
        // by the auto-seeding from Telegram's language_code.
        if (! $user || ! $user->hasChosenLocale()) {
            $this->sendLanguagePicker($chatId, $user);
            return;
        }

        $isFa = $user->locale === 'fa';
        $appUrl = rtrim((string) config('app.url'), '/').'/app';

        $text = $isFa
            ? "به <b>".e(config('ads-platform.brand'))."</b> خوش آمدید.\n".
              "از دکمه‌های زیر برای ورود به مینی‌اپ، پشتیبانی یا راهنما استفاده کنید.\n".
              "برای تغییر زبان /lang را بفرستید."
            : "Welcome to <b>".e(config('ads-platform.brand'))."</b>.\n".
              "Use the buttons below to open the Mini App, contact support or read the help.\n".
              "Send /lang to switch language.";

        SendTelegramMessage::dispatch($chatId, $text, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => $isFa ? '📱 ورود به مینی‌اپ' : '📱 Open Mini App', 'web_app' => ['url' => $appUrl]],
                    ],
                    [
                        ['text' => $isFa ? '💬 پشتیبانی' : '💬 Support', 'callback_data' => self::CB_SUPPORT],
                        ['text' => $isFa ? '💳 پشتیبانی پرداخت' : '💳 Pay support', 'callback_data' => self::CB_PAYSUPPORT],
                    ],
                    [
                        ['text' => $isFa ? '📘 راهنما' : '📘 Help', 'callback_data' => self::CB_HELP],
                        ['text' => $isFa ? '🌐 تغییر زبان' : '🌐 Switch language', 'callback_data' => self::CB_CHANGE_LANG],
                    ],
                ],
            ],
        ]);
    }

    private function sendLanguagePicker(int $chatId, ?User $user): void
    {
        $user ??= User::where('telegram_user_id', $chatId)->first();
        $current = $user?->locale;
        $isFa = $current === 'fa';

        $text = $isFa || $current === null
            ? "👋 لطفاً زبان خود را انتخاب کنید.\nPlease choose your language:"
            : "👋 Please choose your language.\nلطفاً زبان خود را انتخاب کنید:";

        SendTelegramMessage::dispatch($chatId, $text, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🇮🇷 فارسی', 'callback_data' => self::CB_LANG_PREFIX.'fa'],
                        ['text' => '🇬🇧 English', 'callback_data' => self::CB_LANG_PREFIX.'en'],
                    ],
                ],
            ],
        ]);
    }

    private function sendHelpMessage(int $chatId, ?User $user): void
    {
        $user ??= User::where('telegram_user_id', $chatId)->first();
        $isFa = ($user?->locale ?? 'fa') === 'fa';

        $text = $isFa
            ? "<b>راهنمای Ads Platform</b>\n".
              "/start — بازکردن مینی‌اپ و منوی اصلی\n".
              "/lang — تغییر زبان\n".
              "/support — پشتیبانی\n".
              "/paysupport — پیگیری پرداخت\n".
              "/terms — شرایط استفاده\n".
              "/privacy — حریم خصوصی"
            : "<b>Ads Platform help</b>\n".
              "/start — main menu & open Mini App\n".
              "/lang — switch language\n".
              "/support — contact support\n".
              "/paysupport — payment support\n".
              "/terms — terms of service\n".
              "/privacy — privacy policy";

        SendTelegramMessage::dispatch($chatId, $text);
    }

    private function sendSupportMessage(int $chatId, ?User $user, bool $isPaySupport): void
    {
        $user ??= User::where('telegram_user_id', $chatId)->first();
        $isFa = ($user?->locale ?? 'fa') === 'fa';
        $support = ltrim((string) config('ads-platform.support_username'), '@');
        $supportText = $support !== '' ? '@'.htmlspecialchars($support, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ($isFa ? 'پشتیبانی داخل مینی‌اپ' : 'In-app support');

        $text = $isPaySupport
            ? ($isFa
                ? "<b>پشتیبانی پرداخت</b>\nشناسه سفارش/تراکنش و زمان پرداخت را برای {$supportText} بفرستید. رمز پویا، CVV2 یا شماره کامل کارت را هرگز ارسال نکنید."
                : "<b>Payment support</b>\nSend your order/transaction ID and payment time to {$supportText}. Never share OTP, CVV2 or full card number.")
            : ($isFa
                ? "<b>پشتیبانی</b>\nاز بخش پشتیبانی مینی‌اپ تیکت بسازید یا با {$supportText} تماس بگیرید."
                : "<b>Support</b>\nOpen a ticket from the Mini App's support section, or contact {$supportText}.");

        SendTelegramMessage::dispatch($chatId, $text);
    }
}
