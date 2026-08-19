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
     * Manually refresh a user's Telegram profile photo URL.
     *
     * Admins use this when a user's avatar shows the initial-letter fallback
     * in the admin panel (e.g. because the user hasn't logged into the Mini
     * App in a while, so their persisted `photo_url` is stale or expired).
     *
     * What this does:
     *   1. Calls Telegram's Bot API (getUserProfilePhotos + getFile) to
     *      resolve the user's latest profile photo URL.
     *   2. Persists that URL on `users.photo_url` (with a fresh `updated_at`
     *      so the 30-min fast-path window starts now).
     *   3. Invalidates the AvatarController's cached URL for this user so
     *      the next `<img src="/avatars/{id}">` request re-resolves cleanly.
     *
     * Returns a redirect back to the user detail page with a flash message
     * indicating success or failure (e.g. user has no Telegram photo, or
     * the Bot API was unreachable).
     */
    public function refreshPhoto(
        User $user,
        TelegramBotClient $botClient,
        AuditLogger $audit,
    ): RedirectResponse {
        // force:true bypasses the 30-min freshness check so the admin can
        // re-fetch even when photo_url is technically still fresh (e.g. the
        // user uploaded a new Telegram profile photo and the admin wants to
        // see it now).
        $ok = $user->refreshTelegramPhotoUrl($botClient, force: true);

        // Invalidate the AvatarController URL cache for this user so the
        // next request re-resolves via the Bot API instead of returning
        // the previously-cached "no photo" marker or a stale URL.
        Cache::forget("avatar:url:{$user->id}");

        $isFa = app()->isLocale('fa');

        if ($ok) {
            $audit->log('user.photo_refreshed', auth('admin')->user(), $user);

            return back()->with('success', $isFa
                ? 'آدرس عکس پروفایل از تلگرام به‌روزرسانی شد.'
                : 'Profile photo URL refreshed from Telegram.');
        }

        return back()->with('error', $isFa
            ? 'نتوانستیم عکس پروفایل را از تلگرام بگیریم. ممکن است کاربر عکس پروفایل نداشته باشد یا تلگرام در دسترس نباشد.'
            : 'Could not fetch the profile photo from Telegram. The user may have no profile photo, or Telegram is unreachable.');
    }
}
