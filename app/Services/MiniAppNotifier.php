<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Sends short push-style notifications to a user's Telegram chat.
 *
 * Every notification carries an inline "Open Mini App" web_app button so
 * the user can jump straight back into the Mini App with one tap. The
 * button URL includes the user's magic_token so initData auth works
 * even when Telegram fails to inject it.
 *
 * The messages are intentionally short and look like a push
 * notification — they're meant to *alert* the user, not replace the
 * in-app detail page. After tapping the button, the user lands on the
 * relevant screen of the Mini App.
 */
final class MiniAppNotifier
{
    /**
     * Send a free-form notification with the standard "Open Mini App"
     * button. The $routeHint is used to build a deep-link URL inside
     * the Mini App (e.g. "campaigns/42" for an order detail page).
     */
    public function notify(User $user, string $message, ?string $routeHint = null): void
    {
        $chatId = (int) $user->telegram_user_id;
        if ($chatId <= 0) {
            return;
        }

        $isFa = $user->locale === 'fa';
        $buttonLabel = $isFa ? '📱 باز کردن اپ' : '📱 Open App';

        $appUrl = $this->buildAppUrl($user, $routeHint);

        SendTelegramMessage::dispatch($chatId, $message, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => $buttonLabel, 'web_app' => ['url' => $appUrl]],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Notify the user that their order has been registered and is now
     * waiting for payment.
     */
    public function orderCreated(Order $order): void
    {
        $user = $order->user;
        if (! $user) {
            return;
        }
        $isFa = $user->locale === 'fa';
        $message = $isFa
            ? '✅ سفارش شما ثبت شد.'."\n".'شماره سفارش: #'.$order->public_id."\n".'مرحله بعد: انتخاب روش پرداخت.'
            : '✅ Your order has been registered.'."\n".'Order ID: #'.$order->public_id."\n".'Next step: choose a payment method.';

        $this->notify($user, $message, 'campaigns/'.$order->public_id);
    }

    /**
     * Notify the user that their wallet top-up succeeded.
     */
    public function walletTopUpSucceeded(User $user, int $tomanAmount): void
    {
        $isFa = $user->locale === 'fa';
        $message = $isFa
            ? '💰 کیف پول شما شارژ شد.'."\n".'مبلغ: '.number_format($tomanAmount).' تومان.'
            : '💰 Your wallet has been topped up.'."\n".'Amount: '.number_format($tomanAmount).' Toman.';

        $this->notify($user, $message, 'wallet');
    }

    /**
     * Notify the user that their order's status has changed (e.g.
     * support approved it, Telegram approved/rejected, started
     * running, paused, completed).
     */
    public function orderStatusChanged(Order $order, string $newStatusLabel, ?string $note = null): void
    {
        $user = $order->user;
        if (! $user) {
            return;
        }

        // Status transitions are already centralised in CampaignTransitionService,
        // but a few legacy callers still explicitly invoke this notifier after the
        // transition. That used to enqueue the exact same push twice (most visible
        // after wallet payment -> support_review). Suppress only immediate repeats;
        // the 30-second TTL is short enough that a legitimate later transition back
        // to the same state can still notify normally.
        $statusValue = $order->status instanceof \BackedEnum
            ? $order->status->value
            : (string) $order->status;
        $dedupeKey = 'miniapp:order-status-notification:'.$order->getKey().':'.$statusValue;
        if (! Cache::add($dedupeKey, true, now()->addSeconds(30))) {
            return;
        }

        $isFa = $user->locale === 'fa';
        $lines = [
            $isFa ? '🔔 وضعیت سفارش به‌روزرسانی شد.' : '🔔 Your order status was updated.',
            $isFa ? 'سفارش #'.$order->public_id.' — '.$newStatusLabel : 'Order #'.$order->public_id.' — '.$newStatusLabel,
        ];
        if (is_string($note) && trim($note) !== '') {
            $lines[] = trim($note);
        }
        $this->notify($user, implode("\n", $lines), 'campaigns/'.$order->public_id);
    }

    /**
     * Build the Mini App URL with magic_token + optional deep-link path.
     *
     * The path is appended as the URL fragment (#/path) so the Mini App
     * SPA (or server-side redirect) can pick it up after auth.
     */
    private function buildAppUrl(User $user, ?string $routeHint): string
    {
        $base = rtrim((string) config('app.url'), '/').'/app';
        $token = (string) $user->magic_token;
        $query = $token !== '' ? '?t='.urlencode($token) : '';
        $fragment = is_string($routeHint) && $routeHint !== ''
            ? '#'.ltrim($routeHint, '#/')
            : '';

        return $base.$query.$fragment;
    }
}
