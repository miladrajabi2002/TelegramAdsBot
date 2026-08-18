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

            $this->processMessage($request->input('message'));

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
            $appUrl = rtrim((string) config('app.url'), '/').'/app';
            SendTelegramMessage::dispatch((int) $telegramId, 'به Ads Platform خوش آمدید. برای ثبت تبلیغ وارد مینی‌اپ شوید؛ برای پرداخت ریالی نیز شماره متعلق به خودتان را تأیید کنید.', [
                'reply_markup' => [
                    'keyboard' => [
                        [['text' => 'باز کردن مینی‌اپ', 'web_app' => ['url' => $appUrl]]],
                        [['text' => 'تأیید شماره همراه', 'request_contact' => true]],
                    ],
                    'resize_keyboard' => true,
                    'is_persistent' => true,
                ],
            ]);
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
            SendTelegramMessage::dispatch(
                (int) $telegramId,
                "<b>راهنمای Ads Platform</b>\n/start — بازکردن مینی‌اپ و تأیید شماره\n/support — پشتیبانی\n/paysupport — پیگیری پرداخت\n/terms — شرایط استفاده\n/privacy — حریم خصوصی",
            );
        } elseif ($telegramId && in_array($command, ['/support', '/paysupport'], true)) {
            $support = ltrim((string) config('ads-platform.support_username'), '@');
            $supportText = $support !== '' ? '@'.htmlspecialchars($support, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'پشتیبانی داخل مینی‌اپ';
            $messageText = $command === '/paysupport'
                ? "<b>پشتیبانی پرداخت</b>\nشناسه سفارش/تراکنش و زمان پرداخت را برای {$supportText} بفرستید. رمز پویا، CVV2 یا شماره کامل کارت را هرگز ارسال نکنید."
                : "<b>پشتیبانی</b>\nاز بخش پشتیبانی مینی‌اپ تیکت بسازید یا با {$supportText} تماس بگیرید.";
            SendTelegramMessage::dispatch((int) $telegramId, $messageText);
        }

        if ($telegramId && $contactUserId && (string) $telegramId === (string) $contactUserId && $phone) {
            $user = $telegramUserRecord ?? User::where('telegram_user_id', $telegramId)->first();
            if ($user) {
                $user->update([
                    'phone' => '+'.ltrim(preg_replace('/\D/', '', (string) $phone) ?? '', '+'),
                    'phone_verified_at' => now(),
                ]);
                SendTelegramMessage::dispatch((int) $telegramId, 'شماره همراه شما با موفقیت تأیید شد. اکنون می‌توانید احراز هویت پرداخت ریالی را ادامه دهید.');
            }
        }
    }
}
