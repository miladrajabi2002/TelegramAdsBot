<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage;
use App\Models\Order;
use App\Models\User;

/**
 * Sends operational push alerts to the platform sudo (owner) account.
 *
 * The sudo account is configured through TELEGRAM_SODO_ID in the
 * environment. It receives a Telegram push whenever a customer submits a
 * brand-new campaign or resubmits a corrected one, so nothing lands in
 * the support queue unnoticed. All sends are queued (SendTelegramMessage)
 * and silently skipped when the sodo id is not configured.
 */
final class SudoNotifier
{
    /**
     * Alert the sudo account that a brand-new campaign was registered
     * by a customer (order saved, waiting for payment).
     */
    public function campaignSubmitted(Order $order): void
    {
        $this->send('🆕 تبلیغ جدید ثبت شد', $order, 'سفارش تازه ثبت شده و در انتظار پرداخت است.');
    }

    /**
     * Alert the sudo account that a customer resubmitted a corrected
     * campaign (revision sent back to support review).
     */
    public function campaignRevisionSubmitted(Order $order): void
    {
        $this->send('♻️ تبلیغ اصلاح‌شده مجدداً ارسال شد', $order, 'نسخه اصلاح‌شده توسط مشتری برای بررسی پشتیبانی ارسال شد.');
    }

    private function send(string $headline, Order $order, string $footnote): void
    {
        $sodoId = (int) config('services.telegram.sodo_id', 0);
        if ($sodoId <= 0) {
            return;
        }

        $user = $order->user;
        $revision = $order->currentRevision;

        $lines = [
            $headline,
            '',
            '🆔 سفارش: #'.$order->public_id,
        ];

        if ($user instanceof User) {
            $who = trim((string) ($user->display_name ?? ''));
            if ($who === '') {
                $who = trim(trim(($user->first_name ?? '').' '.($user->last_name ?? '')));
            }
            if ((string) ($user->telegram_username ?? '') !== '') {
                $who .= ' (@'.$user->telegram_username.')';
            }
            $who .= ' — ID: '.$user->telegram_user_id;
            $lines[] = '👤 کاربر: '.trim($who);
        }

        if ($revision !== null) {
            $lines[] = '📌 عنوان: '.$revision->internal_title;
            $lines[] = '📝 متن تبلیغ: '.$revision->ad_text;
            $lines[] = '📍 نوع انتشار: '.$this->placementLabel((string) $revision->placement_type);
            $lines[] = '🔄 نسخه: '.$revision->revision_no;
        }

        $lines[] = '💰 بودجه رسانه: '.number_format(intdiv((int) $order->media_budget_irr, 10)).' تومان';
        $lines[] = '💳 مبلغ کل: '.number_format(intdiv((int) $order->total_irr, 10)).' تومان';
        $lines[] = '📊 وضعیت: '.($order->status instanceof \BackedEnum ? $order->status->label('fa') : (string) $order->status);
        $lines[] = '';
        $lines[] = $footnote;

        SendTelegramMessage::dispatch($sodoId, implode("\n", $lines), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '🛠 مشاهده سفارش در پنل مدیریت', 'url' => $this->adminOrderUrl($order)],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Deep link to the order page inside the (hidden-path) admin panel.
     */
    private function adminOrderUrl(Order $order): string
    {
        $prefix = trim((string) config('ads-platform.admin_path_prefix', ''), '/');

        return rtrim((string) config('app.url'), '/')
            .'/'.($prefix !== '' ? $prefix.'/' : '')
            .'orders/'.$order->public_id;
    }

    private function placementLabel(string $placement): string
    {
        return match ($placement) {
            'channel_posts' => 'پست کانال',
            'search_results' => 'نتایج جستجو',
            'bot_messages' => 'پیام ربات',
            default => $placement !== '' ? $placement : 'نامشخص',
        };
    }
}
