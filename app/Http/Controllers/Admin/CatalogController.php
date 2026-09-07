<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SuggestedChannel;
use App\Models\TargetCategory;
use App\Services\AuditLogger;
use App\Services\Telegram\ChannelAvatarFetcher;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(Request $request): View
    {
        $categories = TargetCategory::withCount('channels')->with('channels')->orderBy('sort_order')->get();
        $selectedCategory = $request->filled('category')
            ? $categories->firstWhere('slug', $request->input('category'))
            : null;
        $channels = SuggestedChannel::query()->with('categories')
            ->when($selectedCategory, fn ($query) => $query->whereHas('categories', fn ($category) => $category->whereKey($selectedCategory->getKey())))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->input('q'));
                $query->where(fn ($nested) => $nested->where('title', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%"));
            })
            ->latest()->paginate(30)->withQueryString();

        return view('admin.channels.index', compact('categories', 'channels', 'selectedCategory'));
    }

    private function buildUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        if ($base === '' || preg_match('/[^a-z0-9-]/i', $base)) {
            $base = 'cat-' . substr(md5($title), 0, 8);
        }
        $candidate = $base;
        $suffix = 2;
        while (TargetCategory::query()
            ->where('slug', $candidate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists()) {
            $candidate = $base . '-' . $suffix++;
        }
        return $candidate;
    }

    public function storeCategory(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $title = trim($data['title']);
        $slug = $this->buildUniqueSlug($title);

        $nextSort = (int) (TargetCategory::query()->max('sort_order') ?? -1) + 1;

        $category = TargetCategory::create([
            'title_fa' => $title,
            'title_en' => $title,
            'slug' => $slug,
            'sort_order' => $nextSort,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $audit->log('catalog.category_created', auth('admin')->user(), $category, after: ['title' => $title, 'slug' => $slug]);

        return back()->with('success', 'دسته‌بندی ایجاد شد.');
    }

    public function updateCategory(Request $request, TargetCategory $category, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = $category->only(['title_fa', 'title_en', 'is_active']);
        $title = trim($data['title']);
        $category->update([
            'title_fa' => $title,
            'title_en' => $title,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);
        $audit->log('catalog.category_updated', auth('admin')->user(), $category, before: $before, after: ['title' => $title, 'is_active' => $category->is_active]);

        return back()->with('success', 'دسته‌بندی به‌روزرسانی شد.');
    }

    public function reorderCategories(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'exists:target_categories,id'],
        ]);

        \DB::transaction(function () use ($data, $audit): void {
            foreach (array_values($data['order']) as $position => $id) {
                TargetCategory::whereKey((int) $id)->update(['sort_order' => $position]);
            }
        });

        $audit->log('catalog.categories_reordered', auth('admin')->user(), null, after: ['order' => $data['order']]);

        return response()->json(['ok' => true]);
    }

    public function destroyCategory(TargetCategory $category, AuditLogger $audit): RedirectResponse
    {
        $channelCount = $category->channels()->count();
        $totalCategories = TargetCategory::where('is_active', true)->count();
        if ($totalCategories <= 1) {
            return back()->withErrors('حداقل یک دسته‌بندی باید باقی بماند.');
        }

        $before = $category->only(['slug', 'title_fa', 'title_en']);
        $category->delete();
        $audit->log('catalog.category_deleted', auth('admin')->user(), null, before: $before);

        return back()->with('success', "دسته‌بندی حذف شد. {$channelCount} کانال از آن جدا شدند.");
    }

    public function toggleCategory(TargetCategory $category, AuditLogger $audit): RedirectResponse
    {
        $before = $category->is_active;
        $category->update(['is_active' => ! $before]);
        $audit->log('catalog.category_toggled', auth('admin')->user(), $category, before: ['active' => $before], after: ['active' => $category->is_active]);

        return back()->with('success', $category->is_active ? 'دسته‌بندی فعال شد.' : 'دسته‌بندی غیرفعال شد.');
    }

    /**
     * Resolve a channel's avatar URL.
     *
     * Strategy:
     *   1. If we already have a getChat() response with photo.big_file_id,
     *      try to convert it to a file URL via the Bot API. This returns a
     *      SHORT-LIVED URL (expires in ~1 hour) — fine for immediate
     *      display, but NOT for long-term storage.
     *   2. Fall back to scraping https://t.me/<username> — this returns a
     *      STABLE CDN URL (cdn4.telesco.pe) that never expires. We prefer
     *      this for storage in avatar_url.
     *
     * The t.me fallback runs whenever the Bot API returns no photo, OR when
     * the Bot API photo URL extraction fails. This makes avatar resolution
     * far more reliable than relying on the Bot API alone.
     */
    private function resolveAvatar(
        ?string $username,
        TelegramBotClient $bot,
        ChannelAvatarFetcher $fetcher,
        ?array $chat = null
    ): ?string {
        // Step 1: try Bot API getFile() if we have a chat array with photo.
        if (is_array($chat) && isset($chat['photo']['big_file_id'])) {
            try {
                $file = $bot->getFile($chat['photo']['big_file_id']);
                if ($file !== null && ($file['file_path'] ?? null) !== null) {
                    return $bot->fileDownloadUrl($file['file_path']);
                }
            } catch (\Throwable $e) {
                // Fall through to t.me scraper.
            }
        }
        if (is_array($chat) && isset($chat['photo']['small_file_id'])) {
            try {
                $file = $bot->getFile($chat['photo']['small_file_id']);
                if ($file !== null && ($file['file_path'] ?? null) !== null) {
                    return $bot->fileDownloadUrl($file['file_path']);
                }
            } catch (\Throwable $e) {
                // Fall through to t.me scraper.
            }
        }

        // Step 2: fall back to scraping t.me (stable CDN URL).
        if ($username) {
            return $fetcher->fetchByUsername($username);
        }

        return null;
    }

    public function lookupChannel(Request $request, TelegramBotClient $bot, ChannelAvatarFetcher $fetcher): JsonResponse
    {
        try {
            $request->validate(['q' => ['required', 'string', 'max:128']]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'invalid',
                'message' => $e->validator->errors()->first(),
            ], 422);
        }

        $raw = trim((string) $request->input('q'));
        if ($raw === '') {
            return response()->json(['error' => 'empty'], 422);
        }

        $isNumericChatId = preg_match('/^-?\d{5,}$/', $raw);
        $username = $raw;
        $telegramChatId = null;
        if (! $isNumericChatId) {
            $username = preg_replace('~^https?://t\.me/~i', '', $raw);
            $username = preg_replace('~^@~', '', $username);
            $username = preg_replace('~/.*$~', '', $username);
            $username = trim($username);
            if (! preg_match('/^[A-Za-z0-9_]{4,64}$/', $username)) {
                return response()->json(['error' => 'invalid'], 422);
            }
        } else {
            $telegramChatId = $raw;
        }

        // 1. Try the local catalogue first.
        $local = SuggestedChannel::query()
            ->when($isNumericChatId, fn ($q) => $q->where('telegram_chat_id', $raw))
            ->when(! $isNumericChatId, fn ($q) => $q->where('username', $username))
            ->first();
        if ($local) {
            // If the local row has no avatar, try to backfill it on the fly
            // via the t.me scraper. This self-heals catalogue rows that were
            // created before the avatar fetcher existed.
            $avatar = $local->avatar_url;
            if (!$avatar && $local->username) {
                $avatar = $fetcher->fetchByUsername($local->username);
                if ($avatar) {
                    $local->avatar_url = $avatar;
                    $local->save();
                }
            }
            return response()->json([
                'username' => $local->username,
                'title' => $local->title,
                'members' => (int) $local->members_count,
                'avatar' => $avatar,
                'language' => $local->language,
                'telegram_chat_id' => $local->telegram_chat_id,
                'public_url' => $local->public_url,
                'source' => 'catalog',
            ]);
        }

        // 2. Fall back to Telegram's getChat for public channels / bots.
        try {
            $chatId = $isNumericChatId ? $raw : '@' . $username;
            $chat = $bot->getChat($chatId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ZarinPal admin channel lookup: Telegram getChat threw', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'telegram_unreachable',
                'message' => $e->getMessage(),
            ], 502);
        }

        if (! is_array($chat) || (empty($chat['username']) && empty($chat['id']))) {
            return response()->json(['error' => 'not_found'], 404);
        }

        try {
            $members = $bot->getChatMemberCount($chatId);
        } catch (\Throwable $e) {
            $members = null;
        }

        $resolvedUsername = $chat['username'] ?? $username;
        // Resolve avatar via the unified helper — Bot API first, t.me fallback.
        $photoUrl = $this->resolveAvatar($resolvedUsername, $bot, $fetcher, $chat);

        $resolvedTitle = $chat['title'] ?? $chat['username'] ?? $username;

        return response()->json([
            'username' => $resolvedUsername,
            'title' => $resolvedTitle,
            'members' => $members,
            'avatar' => $photoUrl,
            'language' => 'fa',
            'telegram_chat_id' => $telegramChatId ?? (isset($chat['id']) ? (string) $chat['id'] : null),
            'public_url' => $resolvedUsername ? 'https://t.me/' . $resolvedUsername : null,
            'source' => 'telegram',
        ]);
    }

    public function storeChannel(Request $request, AuditLogger $audit, TelegramBotClient $bot, ChannelAvatarFetcher $fetcher): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{5,32}$/', 'unique:suggested_channels,username'],
            'title' => ['nullable', 'string', 'max:150'],
            'members_count' => ['nullable', 'integer', 'min:0'],
            'language' => ['nullable', Rule::in(['fa', 'en', 'ar', 'other'])],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:target_categories,id'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach (array_unique($data['category_ids']) as $categoryId) {
            $count = TargetCategory::findOrFail($categoryId)->channels()->wherePivot('target_category_id', $categoryId)->count();
            if ($count >= config('ads-platform.max_channels_per_category', 30)) {
                throw ValidationException::withMessages(['category_ids' => 'یکی از دسته‌ها به سقف 30 کانال رسیده است.']);
            }
        }

        $username = ltrim($data['username'], '@');

        $title = trim((string) ($data['title'] ?? ''));
        $members = isset($data['members_count']) ? (int) $data['members_count'] : null;
        $avatarUrl = null;
        $telegramChatId = null;

        $chat = null;
        if ($title === '' || $members === null) {
            try {
                $chat = $bot->getChat('@' . $username);
            } catch (\Throwable $e) {
                $chat = null;
            }
            if (is_array($chat)) {
                if ($title === '') {
                    $title = (string) ($chat['title'] ?? $username);
                }
                if ($members === null) {
                    try { $members = $bot->getChatMemberCount('@' . $username); } catch (\Throwable $e) { $members = 0; }
                }
                $telegramChatId = isset($chat['id']) ? (string) $chat['id'] : null;
            }
        }

        // Resolve avatar via unified helper (Bot API + t.me fallback).
        $avatarUrl = $this->resolveAvatar($username, $bot, $fetcher, $chat);

        if ($title === '') {
            $title = $username;
        }
        if ($members === null) {
            $members = 0;
        }

        $channel = SuggestedChannel::create([
            'username' => $username,
            'title' => $title,
            'public_url' => 'https://t.me/' . $username,
            'avatar_url' => $avatarUrl,
            'telegram_chat_id' => $telegramChatId,
            'language' => $data['language'] ?? 'fa',
            'members_count' => $members,
            'eligibility_status' => $members > $this->minimumMembers() ? 'eligible' : 'ineligible',
            'is_featured' => false,
            'is_active' => true,
            'last_verified_at' => now(),
            'internal_note' => $data['internal_note'] ?? null,
        ]);

        foreach (array_unique($data['category_ids']) as $categoryId) {
            $position = (int) TargetCategory::find($categoryId)->channels()->max('target_category_channels.position') + 1;
            $channel->categories()->attach($categoryId, ['position' => $position]);
        }

        $audit->log('catalog.channel_created', auth('admin')->user(), $channel, after: ['username' => $username, 'categories' => $data['category_ids']]);

        return back()->with('success', 'کانال پیشنهادی اضافه شد.');
    }

    public function edit(SuggestedChannel $channel): View
    {
        $channel->load('categories');
        $categories = TargetCategory::query()->withCount('channels')->orderBy('sort_order')->get();

        return view('admin.channels.edit', compact('channel', 'categories'));
    }

    public function update(Request $request, SuggestedChannel $channel, AuditLogger $audit, TelegramBotClient $bot, ChannelAvatarFetcher $fetcher): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{5,32}$/', Rule::unique('suggested_channels', 'username')->ignore($channel)],
            'title' => ['nullable', 'string', 'max:150'],
            'members_count' => ['nullable', 'integer', 'min:0'],
            'language' => ['nullable', Rule::in(['fa', 'en', 'ar', 'other'])],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:target_categories,id'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'refresh_from_telegram' => ['nullable', 'boolean'],
        ]);

        $categoryIds = array_values(array_unique(array_map('intval', $data['category_ids'])));
        $existingIds = $channel->categories()->pluck('target_categories.id')->map(fn ($id) => (int) $id);
        foreach ($categoryIds as $categoryId) {
            if ($existingIds->contains($categoryId)) {
                continue;
            }
            if (TargetCategory::findOrFail($categoryId)->channels()->count() >= config('ads-platform.max_channels_per_category', 30)) {
                throw ValidationException::withMessages(['category_ids' => 'یکی از دسته‌ها به سقف 30 کانال رسیده است.']);
            }
        }

        $before = $channel->only(['username', 'title', 'language', 'members_count', 'is_active']);
        $username = ltrim($data['username'], '@');

        $title = trim((string) ($data['title'] ?? ''));
        $members = isset($data['members_count']) ? (int) $data['members_count'] : null;
        $telegramChatId = $channel->telegram_chat_id;

        $shouldRefresh = ! empty($data['refresh_from_telegram']) || $title === '' || $members === null;
        $chat = null;
        if ($shouldRefresh) {
            try { $chat = $bot->getChat('@' . $username); } catch (\Throwable $e) { $chat = null; }
            if (is_array($chat)) {
                if ($title === '' || ! empty($data['refresh_from_telegram'])) {
                    $title = (string) ($chat['title'] ?? $username);
                }
                if ($members === null || ! empty($data['refresh_from_telegram'])) {
                    try { $members = $bot->getChatMemberCount('@' . $username); } catch (\Throwable $e) { $members = (int) $channel->members_count; }
                }
                $telegramChatId = isset($chat['id']) ? (string) $chat['id'] : $telegramChatId;
            }
        }

        // Resolve avatar via unified helper (Bot API + t.me fallback).
        // On explicit "refresh from Telegram" requests, we always re-fetch
        // (overwriting the existing avatar_url). Otherwise, keep the stored
        // avatar if it's already a stable CDN URL.
        $avatarUrl = $channel->avatar_url;
        if ($shouldRefresh) {
            $avatarUrl = $this->resolveAvatar($username, $bot, $fetcher, $chat);
            // If both Bot API and t.me failed, keep the old avatar rather
            // than wiping it to null (better to show a stale avatar than no
            // avatar at all).
            if (!$avatarUrl) {
                $avatarUrl = $channel->avatar_url;
            }
        }

        if ($title === '') {
            $title = $username;
        }
        if ($members === null) {
            $members = (int) $channel->members_count;
        }

        $channel->update([
            'username' => $username,
            'title' => $title,
            'public_url' => 'https://t.me/' . $username,
            'avatar_url' => $avatarUrl,
            'telegram_chat_id' => $telegramChatId,
            'language' => $data['language'] ?? $channel->language,
            'members_count' => $members,
            'eligibility_status' => $members > $this->minimumMembers() ? 'eligible' : 'ineligible',
            'last_verified_at' => now(),
            'internal_note' => $data['internal_note'] ?? null,
        ]);

        $pivot = [];
        foreach ($categoryIds as $categoryId) {
            $currentPosition = $channel->categories->firstWhere('id', $categoryId)?->pivot?->position;
            $pivot[$categoryId] = [
                'position' => $currentPosition ?: ((int) TargetCategory::findOrFail($categoryId)->channels()->max('target_category_channels.position') + 1),
            ];
        }
        $channel->categories()->sync($pivot);
        $audit->log('catalog.channel_updated', auth('admin')->user(), $channel, before: $before, after: [
            ...$channel->only(['username', 'title', 'language', 'members_count', 'is_active']),
            'categories' => $categoryIds,
        ]);

        return redirect()->route('admin.channels.index')->with('success', 'اطلاعات کانال به‌روزرسانی شد.');
    }

    public function toggleChannel(SuggestedChannel $channel, AuditLogger $audit): RedirectResponse
    {
        $before = $channel->is_active;
        $channel->update(['is_active' => ! $before]);
        $audit->log('catalog.channel_toggled', auth('admin')->user(), $channel, before: ['active' => $before], after: ['active' => $channel->is_active]);

        return back()->with('success', 'وضعیت کانال تغییر کرد.');
    }

    public function destroyChannel(SuggestedChannel $channel, AuditLogger $audit): RedirectResponse
    {
        $before = $channel->only(['id', 'username', 'title']);
        $channel->delete();
        $audit->log('catalog.channel_deleted', auth('admin')->user(), null, before: $before);

        return back()->with('success', 'کانال حذف شد. سوابق کمپین‌های قبلی دست‌نخورده باقی می‌مانند.');
    }

    private function minimumMembers(): int
    {
        $setting = Setting::where('key', 'minimum_channel_members')->first();

        return max(1000, (int) data_get($setting?->value, 'value', config('ads-platform.minimum_target_members', 1000)));
    }
}
