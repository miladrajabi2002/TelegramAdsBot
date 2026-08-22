<?php

namespace App\Http\Controllers\MiniApp;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CampaignRevision;
use App\Models\Order;
use App\Models\TargetCategory;
use App\Services\CampaignContentValidator;
use App\Services\CampaignTransitionService;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Handles customer corrections without changing the original campaign creation flow.
 *
 * The correction path intentionally accepts the same target identifiers as the create
 * path (catalog ids, usernames and Telegram chat ids), preserves the previous media
 * when no new file is uploaded, and lets the authenticated owner preview the private
 * media through a same-origin endpoint.
 */
class CampaignCorrectionController extends Controller
{
    public function edit(Request $request, Order $campaign): View
    {
        $order = $campaign;
        abort_unless((int) $order->user_id === (int) $request->user()->getKey(), 404);
        abort_unless($order->status === OrderStatus::ChangesRequested, 422, 'فقط سفارش نیازمند اصلاح قابل ویرایش است.');

        $order->load('currentRevision.targets');
        $categories = TargetCategory::query()->where('is_active', true)
            ->with(['channels' => fn ($query) => $query->where('is_active', true)
                ->orderByRaw("CASE WHEN language = 'fa' THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN is_featured = 1 THEN 0 ELSE 1 END")
                ->orderByDesc('members_count')
                ->limit(30)])
            ->orderBy('sort_order')->get();
        $suggestedChannels = \App\Models\SuggestedChannel::query()->where('is_active', true)
            ->orderByRaw("CASE WHEN language = 'fa' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN is_featured = 1 THEN 0 ELSE 1 END")
            ->orderByDesc('members_count')
            ->limit(60)->get();

        $draft = $order;
        $editing = true;
        $quote = [
            'media_budget_toman' => intdiv((int) $order->media_budget_irr, 10),
            'service_markup_percent' => (int) $order->service_markup_bps / 100,
            'service_fee_toman' => intdiv((int) $order->service_fee_irr, 10),
            'gateway_fee_toman' => intdiv((int) $order->gateway_fee_irr, 10),
            'total_toman' => intdiv((int) $order->total_irr, 10),
            'total_usd' => (float) $order->usd_amount,
            'usd_to_irr_rate' => (float) $order->usd_to_irr_rate,
            'gram_to_usd_rate' => (float) $order->gram_to_usd_rate,
            'media_budget_gram' => $order->usd_to_irr_rate > 0 && $order->gram_to_usd_rate > 0
                ? ((float) $order->media_budget_irr) / ((float) $order->usd_to_irr_rate * (float) $order->gram_to_usd_rate)
                : 0,
        ];
        $minimumOrderToman = intdiv((int) config('ads-platform.minimum_order_irr', 1_000_000), 10);
        $zarinPayEnabled = (bool) config('services.zarinpay.enabled')
            || (app()->isLocal() && (bool) config('services.zarinpay.mock'));
        $nowPaymentsEnabled = (bool) config('services.nowpayments.enabled');

        return view('app.campaigns.edit', compact(
            'categories', 'draft', 'editing', 'quote', 'order', 'zarinPayEnabled',
            'nowPaymentsEnabled', 'suggestedChannels', 'minimumOrderToman',
        ));
    }

    public function update(
        Request $request,
        Order $campaign,
        CampaignContentValidator $contentValidator,
        CampaignTransitionService $transitions,
        TelegramBotClient $botClient,
    ): RedirectResponse {
        $order = $campaign;
        abort_unless((int) $order->user_id === (int) $request->user()->getKey(), 404);
        abort_unless($order->status === OrderStatus::ChangesRequested, 422);

        $data = $request->validate([
            'internal_title' => ['required', 'string', 'max:120'],
            'ad_text' => ['required', 'string', 'max:160', 'not_regex:/\R/u'],
            'destination_url' => ['required', 'url:http,https', 'max:2048'],
            'placement_type' => ['required', Rule::in(['channel_posts', 'search_results', 'bot_messages'])],
            'impression_goal' => ['nullable', 'integer', 'min:1000', 'max:1000000000'],
            'frequency_cap' => ['nullable', 'integer', 'min:1', 'max:10'],
            'daily_view_limit_per_user' => ['required', 'integer', 'min:1', 'max:4'],
            'plan' => ['required', Rule::in(['standard', 'competitive'])],
            'cpm_gram' => ['required', 'numeric', 'min:0.1', 'max:1000000'],
            'language' => ['nullable', Rule::in(['fa', 'en'])],
            'media_budget_toman' => ['required', 'integer'],

            // Keep correction target validation aligned with create(). The old
            // correction rule only accepted suggested_channels integer ids; that
            // made preserved manual/search targets fail after the user edited an
            // already-rejected campaign.
            'target_channel_ids' => ['required', 'array', 'min:1', 'max:100'],
            'target_channel_ids.*' => ['string', 'max:128'],
            'manual_channels' => ['nullable', 'string', 'max:5000'],

            'search_keywords' => ['nullable', 'array', 'max:30'],
            'search_keywords.*' => ['string', 'min:4', 'max:64'],

            // A correction may replace the media. If omitted, CampaignRevision's
            // model hook copies the previous revision media automatically.
            'ad_media' => ['nullable', 'file', 'max:51200', 'mimes:jpg,jpeg,png,webp,mp4,mov,webm'],
            'terms_accepted' => ['accepted'],
        ], [
            'target_channel_ids.*.string' => 'کانال یا ربات هدف معتبر نیست. دوباره آن را انتخاب کنید.',
            'ad_media.max' => 'حجم تصویر یا ویدیو حداکثر ۵۰ مگابایت است.',
        ]);

        $destinationTypeMap = [
            'channel_posts' => 'channel',
            'bot_messages' => 'bot',
            'search_results' => 'channel',
        ];
        $data['destination_type'] = $destinationTypeMap[$data['placement_type']] ?? 'channel';

        if ((int) $data['media_budget_toman'] * 10 !== (int) $order->media_budget_irr) {
            throw ValidationException::withMessages([
                'media_budget_toman' => 'بودجه سفارش پرداخت‌شده در مرحله اصلاح قابل تغییر نیست.',
            ]);
        }

        $adTextErrors = $contentValidator->adTextErrors($data['ad_text']);
        if ($adTextErrors !== []) {
            return back()->withInput()->withErrors(['ad_text' => implode(' ', $adTextErrors)]);
        }

        $destinationErrors = $contentValidator->destinationUrlErrors($data['destination_url']);
        if ($destinationErrors !== []) {
            return back()->withInput()->withErrors(['destination_url' => implode(' ', $destinationErrors)]);
        }

        $riskFlags = $contentValidator->riskFlags($data['ad_text'], $data['destination_url']);

        $newMediaPath = null;
        $newMediaType = null;
        if ($request->hasFile('ad_media') && $request->file('ad_media')->isValid()) {
            $file = $request->file('ad_media');
            $newMediaType = str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image';
            $filename = Str::random(40).'.'.strtolower((string) $file->getClientOriginalExtension());
            $newMediaPath = $file->storeAs('ad-media', $filename, 'local');
        }

        try {
            DB::transaction(function () use (
                $request,
                $order,
                $data,
                $riskFlags,
                $transitions,
                $newMediaPath,
                $newMediaType,
                $botClient,
            ): void {
                $attributes = [
                    'revision_no' => ((int) $order->revisions()->max('revision_no')) + 1,
                    'internal_title' => trim($data['internal_title']),
                    'ad_text' => trim($data['ad_text']),
                    'destination_type' => $data['destination_type'],
                    'destination_url' => $data['destination_url'],
                    'placement_type' => $data['placement_type'],
                    'targeting_payload' => [
                        'mode' => $data['placement_type'],
                        'automated_content_flags' => $riskFlags,
                    ],
                    'impression_goal' => $data['impression_goal'] ?? null,
                    'frequency_cap' => $data['frequency_cap'] ?? null,
                    'daily_view_limit_per_user' => (int) $data['daily_view_limit_per_user'],
                    'plan' => $data['plan'],
                    'cpm_gram' => $data['cpm_gram'],
                    'language' => $data['language'] ?? $request->user()->locale,
                    'search_keywords' => $data['search_keywords'] ?? null,
                ];

                if (is_string($newMediaPath) && $newMediaPath !== '') {
                    $attributes['ad_media_path'] = $newMediaPath;
                    $attributes['ad_media_type'] = $newMediaType;
                    $attributes['ad_media_disk'] = 'local';
                }

                /** @var CampaignRevision $revision */
                $revision = $order->revisions()->create($attributes);
                $this->storeTargets($revision, $data, $botClient);
                $order->update(['current_revision_id' => $revision->getKey()]);

                $transitions->transition(
                    $order,
                    OrderStatus::SupportReview,
                    $request->user(),
                    'customer_revision_submitted',
                );

                if ($riskFlags !== []) {
                    $order->operatorTasks()->create([
                        'type' => 'content_risk_review',
                        'status' => 'open',
                        'context' => [
                            'flags' => $riskFlags,
                            'source' => 'heuristic_v1',
                            'revision_id' => $revision->id,
                        ],
                    ]);
                }
            });
        } catch (Throwable $exception) {
            if (is_string($newMediaPath) && $newMediaPath !== '') {
                try {
                    Storage::disk('local')->delete($newMediaPath);
                } catch (Throwable) {
                    // Best-effort orphan cleanup only.
                }
            }
            throw $exception;
        }

        return redirect()->route('app.campaigns.show', $order)
            ->with('success', 'نسخه اصلاح‌شده برای بررسی پشتیبانی ارسال شد.');
    }

    /**
     * Serve the current revision media only to the campaign owner.
     */
    public function adMedia(Request $request, Order $campaign): StreamedResponse
    {
        $order = $campaign;
        abort_unless((int) $order->user_id === (int) $request->user()->getKey(), 404);

        $order->loadMissing('currentRevision');
        $revision = $order->currentRevision;
        $path = $revision?->ad_media_path;
        $disk = $revision?->ad_media_disk ?: 'local';

        if (! is_string($path) || trim($path) === '') {
            abort(404, 'Attached ad media was not found.');
        }

        try {
            if (! Storage::disk($disk)->exists($path)) {
                abort(404, 'Attached ad media was not found.');
            }
            $mime = Storage::disk($disk)->mimeType($path)
                ?: ($revision?->ad_media_type === 'video' ? 'video/mp4' : 'image/jpeg');
            $size = (int) Storage::disk($disk)->size($path);
        } catch (Throwable) {
            abort(404, 'Attached ad media was not found.');
        }

        return response()->stream(function () use ($disk, $path): void {
            $stream = Storage::disk($disk)->readStream($path);
            if ($stream === false) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Length' => $size,
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline; filename="campaign-media-'.$order->public_id.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function storeTargets(CampaignRevision $revision, array $data, TelegramBotClient $botClient): void
    {
        $seenUsernames = [];
        $items = collect($data['target_channel_ids'] ?? [])
            ->map(fn ($value) => is_string($value) || is_int($value) ? trim((string) $value) : null)
            ->filter()
            ->unique()
            ->take(100);

        foreach ($items as $value) {
            $channel = null;
            $isSuggestedId = preg_match('/^[1-9]\d{0,18}$/', $value) === 1;

            if ($isSuggestedId) {
                $channel = \App\Models\SuggestedChannel::query()
                    ->where('is_active', true)
                    ->find((int) $value);
            }

            if (! $channel && str_starts_with($value, '-')) {
                $channel = \App\Models\SuggestedChannel::query()
                    ->where('is_active', true)
                    ->where('telegram_chat_id', $value)
                    ->first();
            }

            if (! $channel) {
                $username = $this->normalizeUsername($value);
                if ($username !== null && ! ctype_digit($username)) {
                    $channel = \App\Models\SuggestedChannel::query()
                        ->where('is_active', true)
                        ->where('username', $username)
                        ->first();
                }
            }

            if ($channel) {
                $key = mb_strtolower((string) $channel->username);
                if (isset($seenUsernames[$key])) {
                    continue;
                }
                $seenUsernames[$key] = true;

                $revision->targets()->create([
                    'suggested_channel_id' => $channel->getKey(),
                    'source' => 'catalog',
                    'channel_username' => $channel->username,
                    'channel_title' => $channel->title,
                    'public_url' => $channel->public_url,
                    'members_snapshot' => $channel->members_count,
                    'validation_status' => $channel->eligibility_status,
                ]);
                continue;
            }

            $username = $this->normalizeUsername($value);

            // Search results can post a Telegram chat id rather than a catalog
            // row id. Resolve that id to its public username so corrections of
            // both channel and bot targets remain stable after an admin reject.
            if (($username === null || ctype_digit($username)) && preg_match('/^-?\d{5,}$/', $value) === 1) {
                try {
                    $chat = $botClient->getChat($value);
                    $resolved = is_array($chat) ? trim((string) ($chat['username'] ?? '')) : '';
                    $username = $this->normalizeUsername($resolved);
                } catch (Throwable) {
                    $username = null;
                }
            }

            if ($username === null || ctype_digit($username)) {
                continue;
            }

            $key = mb_strtolower($username);
            if (isset($seenUsernames[$key])) {
                continue;
            }
            $seenUsernames[$key] = true;

            $revision->targets()->create([
                'source' => 'manual',
                'channel_username' => $username,
                'public_url' => 'https://t.me/'.$username,
                'validation_status' => 'pending',
            ]);
        }

        $manual = preg_split('/[\s,]+/', (string) ($data['manual_channels'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_unique($manual), 0, 100) as $value) {
            $username = $this->normalizeUsername((string) $value);
            if ($username === null) {
                continue;
            }

            $key = mb_strtolower($username);
            if (isset($seenUsernames[$key])) {
                continue;
            }
            $seenUsernames[$key] = true;

            $revision->targets()->create([
                'source' => 'manual',
                'channel_username' => $username,
                'public_url' => 'https://t.me/'.$username,
                'validation_status' => 'pending',
            ]);
        }
    }

    private function normalizeUsername(string $value): ?string
    {
        $username = preg_replace('~^https?://t\.me/~i', '', trim($value));
        $username = ltrim((string) $username, '@/');
        $username = (string) preg_replace('~/.*$~', '', $username);

        return preg_match('/^[A-Za-z0-9_]{4,64}$/', $username) === 1 ? $username : null;
    }
}
