<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastBatch;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    public function index(): View
    {
        $broadcasts = Broadcast::withCount([
            'recipients',
            'recipients as sent_count' => fn ($q) => $q->where('status', 'sent'),
            'recipients as failed_count' => fn ($q) => $q->where('status', 'failed'),
        ])->latest()->paginate(20);
        $broadcasts->getCollection()->each(function (Broadcast $broadcast): void {
            $broadcast->setAttribute('recipient_count', $broadcast->recipients_count);
            $broadcast->setAttribute('audience', data_get($broadcast->audience_filters, 'audience', 'all'));
        });
        $finished = BroadcastRecipient::whereIn('status', ['sent', 'failed']);
        $finishedCount = (clone $finished)->count();
        $stats = [
            'sent_today' => BroadcastRecipient::where('status', 'sent')->where('sent_at', '>=', now()->startOfDay())->count(),
            'queued' => BroadcastRecipient::whereIn('status', ['queued', 'retry'])->count(),
            'success_rate' => $finishedCount > 0
                ? round((clone $finished)->where('status', 'sent')->count() * 100 / $finishedCount, 1)
                : 0,
            'failed_today' => BroadcastRecipient::where('status', 'failed')->where('updated_at', '>=', now()->startOfDay())->count(),
        ];
        $audienceOptions = [
            'all' => ['label_fa' => 'همه کاربران فعال', 'label_en' => 'All active users'],
            'rial_verified' => ['label_fa' => 'احرازشده ریالی', 'label_en' => 'Rial-verified users'],
            'has_balance' => ['label_fa' => 'دارای موجودی کیف پول', 'label_en' => 'Users with wallet balance'],
            'has_campaign' => ['label_fa' => 'دارای سفارش', 'label_en' => 'Users with campaigns'],
        ];

        return view('admin.broadcasts.index', compact('broadcasts', 'stats', 'audienceOptions'));
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:4096'],
            'media' => [
                'nullable', 'file', 'max:51200',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm',
            ],
            'audience' => ['required', Rule::in(['all', 'rial_verified', 'active_customers', 'has_balance', 'has_campaign'])],
            'locale' => ['nullable', Rule::in(['fa', 'en'])],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'confirmed' => ['accepted'],
        ]);

        if ($request->hasFile('media') && mb_strlen($data['message']) > 1024) {
            throw ValidationException::withMessages([
                'message' => app()->isLocale('fa')
                    ? 'متن همراه فایل حداکثر ۱۰۲۴ نویسه است.'
                    : 'A media caption may contain at most 1024 characters.',
            ]);
        }

        $mediaType = null;
        $mediaPath = null;
        if ($request->hasFile('media')) {
            $file = $request->file('media');
            $mime = strtolower((string) $file->getMimeType());
            $mediaType = $mime === 'image/gif'
                ? 'animation'
                : (str_starts_with($mime, 'image/') ? 'photo' : 'video');
            if ($mediaType === 'photo' && $file->getSize() > 10 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'media' => app()->isLocale('fa')
                        ? 'حجم عکس برای ارسال تلگرام حداکثر ۱۰ مگابایت است.'
                        : 'Telegram photos may be at most 10 MB.',
                ]);
            }
            $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
            $extension = preg_match('/^[a-z0-9]{2,5}$/', $extension) ? $extension : 'bin';
            $mediaPath = $file->storeAs('broadcast-media', Str::uuid().'.'.$extension, 'local');
            if (! is_string($mediaPath) || $mediaPath === '') {
                throw ValidationException::withMessages([
                    'media' => app()->isLocale('fa') ? 'ذخیره فایل ناموفق بود.' : 'The media file could not be stored.',
                ]);
            }
        }
        try {
            $scheduledAt = filled($data['scheduled_at'] ?? null) ? Carbon::parse($data['scheduled_at']) : now();
            $balanceUserIds = $data['audience'] === 'has_balance'
                ? LedgerAccount::query()
                    ->join('ledger_entries', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
                    ->where('ledger_accounts.owner_type', (new User)->getMorphClass())
                    ->whereIn('ledger_accounts.type', ['wallet_available', 'ad_credit_restricted'])
                    ->select('ledger_accounts.owner_id')
                    ->groupBy('ledger_accounts.owner_id')
                    ->havingRaw("SUM(CASE WHEN ledger_entries.direction = 'credit' THEN ledger_entries.amount_minor ELSE -ledger_entries.amount_minor END) > 0")
                    ->pluck('ledger_accounts.owner_id')
                : collect();

            $broadcast = DB::transaction(function () use ($data, $scheduledAt, $balanceUserIds, $mediaType, $mediaPath): Broadcast {
                $broadcast = Broadcast::create([
                    'admin_id' => auth('admin')->id(),
                    'title' => $data['title'],
                    'message' => $data['message'],
                    'media_type' => $mediaType,
                    'media_disk' => $mediaPath ? 'local' : null,
                    'media_path' => $mediaPath,
                    'audience_filters' => ['audience' => $data['audience'], 'locale' => $data['locale'] ?? null],
                    'status' => 'queued',
                    'scheduled_at' => $scheduledAt,
                ]);

                User::query()
                    ->where('account_status', 'active')
                    ->when($data['audience'] === 'rial_verified', fn ($q) => $q->where('kyc_level', 'rial_verified'))
                    ->when(in_array($data['audience'], ['active_customers', 'has_campaign'], true), fn ($q) => $q->whereHas('orders'))
                    ->when($data['audience'] === 'has_balance', fn ($q) => $q->whereIn('id', $balanceUserIds))
                    ->when(filled($data['locale'] ?? null), fn ($q) => $q->where('locale', $data['locale']))
                    ->select(['id'])->chunkById(500, function ($users) use ($broadcast): void {
                        $now = now();
                        DB::table('broadcast_recipients')->insertOrIgnore($users->map(fn ($user) => [
                            'broadcast_id' => $broadcast->getKey(),
                            'user_id' => $user->getKey(),
                            'status' => 'queued',
                            'attempts' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all());
                    });

                return $broadcast;
            });
        } catch (\Throwable $exception) {
            if ($mediaPath) {
                Storage::disk('local')->delete($mediaPath);
            }
            throw $exception;
        }

        SendBroadcastBatch::dispatch($broadcast->getKey())->delay($scheduledAt);
        $audit->log('broadcast.queued', auth('admin')->user(), $broadcast, after: [
            'audience' => $data['audience'],
            'locale' => $data['locale'] ?? null,
            'scheduled_at' => $scheduledAt->toIso8601String(),
            'recipient_count' => $broadcast->recipients()->count(),
            'media_type' => $mediaType,
        ]);

        return back()->with('success', 'ارسال در صف قرار گرفت و بدون وابستگی به زمان اجرای صفحه انجام می‌شود.');
    }
}
