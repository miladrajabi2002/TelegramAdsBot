<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $term = trim((string) $request->input('q'));
                $query->where(function (Builder $nested) use ($term): void {
                    $nested->where('display_name', 'like', "%{$term}%")
                        ->orWhere('telegram_username', 'like', "%{$term}%")
                        ->orWhere('telegram_user_id', $term)
                        ->orWhere('phone', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('kyc_level'), fn (Builder $q) => $q->where('kyc_level', $request->input('kyc_level')))
            ->when($request->filled('account_status'), fn (Builder $q) => $q->where('account_status', $request->input('account_status')))
            ->with(['ledgerAccounts.entries:id,ledger_account_id,direction,amount_minor'])
            ->withCount(['orders', 'paymentIntents'])->latest()->paginate(25)->withQueryString();

        $users->getCollection()->each(function (User $user): void {
            $user->setAttribute('wallet_balance_irr', $user->ledgerAccounts
                ->whereIn('type', ['wallet_available', 'ad_credit_restricted'])
                ->sum(fn ($account) => $account->balance()));
        });

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user): View
    {
        $user->load([
            'kycApplications' => fn ($q) => $q->latest('version'),
            'fundingCards',
            'orders.currentRevision',
            'paymentIntents.attempts',
            'ledgerAccounts.entries.transaction',
        ]);
        $user->loadCount('orders');

        $balances = $user->ledgerAccounts->mapWithKeys(fn ($account) => [$account->type => $account->balance()]);
        $orders = $user->orders->sortByDesc('created_at')->take(8);
        $transactions = $user->paymentIntents->sortByDesc('created_at')->take(8);
        $tickets = $user->supportTickets()->latest('last_message_at')->limit(6)->get();
        $activities = AuditLog::query()
            ->where(function ($query) use ($user): void {
                $query->where(fn ($subject) => $subject->where('subject_type', $user->getMorphClass())->where('subject_id', $user->getKey()))
                    ->orWhere(fn ($actor) => $actor->where('actor_type', $user->getMorphClass())->where('actor_id', $user->getKey()));
            })
            ->latest()->limit(12)->get();

        $fundingCards = $user->fundingCards;
        $availableBalanceIrr = (int) $balances->get('wallet_available', 0) + (int) $balances->get('ad_credit_restricted', 0);
        $heldBalanceIrr = (int) $balances->get('wallet_reserved', 0);
        $lifetimeSpendIrr = (int) $user->orders()->where('payment_status', 'paid')->sum('total_irr');

        return view('admin.users.show', compact(
            'user', 'balances', 'orders', 'transactions', 'tickets', 'activities',
            'fundingCards', 'availableBalanceIrr', 'heldBalanceIrr', 'lifetimeSpendIrr',
        ));
    }

    /**
     * Force the next avatar request to re-check Telegram.
     *
     * We intentionally never persist Telegram's file download URL because it
     * contains the bot token. AvatarController downloads and caches the bytes
     * server-side instead.
     */
    public function refreshPhoto(
        User $user,
        TelegramBotClient $botClient,
        AuditLogger $audit,
    ): RedirectResponse {
        Cache::forget("avatar:image:{$user->id}");

        $ok = $user->refreshTelegramPhotoUrl($botClient, force: true);
        $isFa = app()->isLocale('fa');

        if ($ok) {
            $audit->log('user.photo_refreshed', auth('admin')->user(), $user);

            return back()->with('success', $isFa
                ? 'عکس پروفایل از تلگرام دوباره بررسی شد. در بارگذاری بعدی نسخه تازه نمایش داده می‌شود.'
                : 'The Telegram profile photo was re-checked. The fresh version will be served on the next load.');
        }

        return back()->with('error', $isFa
            ? 'نتوانستیم عکس پروفایل را از تلگرام بگیریم. ممکن است کاربر عکس نداشته باشد یا تلگرام در دسترس نباشد.'
            : 'Could not fetch the profile photo from Telegram. The user may have no profile photo, or Telegram is unreachable.');
    }
}
