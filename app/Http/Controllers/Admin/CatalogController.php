<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SuggestedChannel;
use App\Models\TargetCategory;
use App\Services\AuditLogger;
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

    /**
     * Build a unique slug for a category when the admin doesn't supply one.
     *
     * Persian titles can't be slugged directly, so we use a stable hash of
     * the title plus a short random suffix to guarantee uniqueness across
     * rows. ASCII titles are passed through Str::slug() first.
     */
    private function buildUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        if ($base === '' || preg_match('/[^a-z0-9-]/i', $base)) {
            // Non-ASCII (Persian, etc.) — fall back to a stable hash.
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

    /**
     * Create a new category — only `title` (and optionally active toggle)
     * are collected from the admin. The slug is auto-generated, and the
     * description/icon columns are kept in the schema for backward-compat
     * but no longer exposed in the UI.
     */
    public function storeCategory(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $title = trim($data['title']);
        $slug = $this->buildUniqueSlug($title);

        // Determine the next sort_order so the new category appears last.
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

    /**
     * Update an existing category — admin can rename it and toggle active.
     * Slug stays auto-managed; sort_order is updated via the dedicated
     * reorder endpoint (drag-and-drop).
     */
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

    /**
     * Bulk-update sort_order from the drag-and-drop reorder UI.
     *
     * Receives a JSON body like `{"order": [3, 1, 5, 2]}` — the IDs in the
     * new display order. We renumber them 0..N-1 inside a transaction so
     * the smallint column stays tidy and the unique-index never trips.
     */
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

    /**
     * Softly delete a category. The `target_category_channels` pivot cascades
     * on delete so the channels themselves remain intact (just detached).
     * Past campaign rows are NOT affected because `campaign_targets` has
     * no FK to `target_categories`.
     *
     * We refuse deletion when the category is the only one that has active
     * channels, to prevent the user-side channel-picker from going empty.
     */
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

    /**
     * Toggle category is_active without going through the full update form.
     */
    public function toggleCategory(TargetCategory $category, AuditLogger $audit): RedirectResponse
    {
        $before = $category->is_active;
        $category->update(['is_active' => ! $before]);
        $audit->log('catalog.category_toggled', auth('admin')->user(), $category, before: ['active' => $before], after: ['active' => $category->is_active]);

        return back()->with('success', $category->is_active ? 'دسته‌بندی فعال شد.' : 'دسته‌بندی غیرفعال شد.');
    }

    /**
     * AJAX endpoint — admin types a Telegram @username (or t.me link, or
     * numeric chat id) and we return whatever we can resolve:
     *   {
     *     "username": "...",
     *     "title": "...",
     *     "members": 12345,
     *     "avatar": "https://api.telegram.org/file/bot.../...",
     *     "language": "fa",
     *     "telegram_chat_id": "-1001234567890",
     *     "public_url": "https://t.me/username"
     *   }
     *
     * Resolution path:
     *   1. Check the local suggested_channels catalogue (instant, cached avatar).
     *   2. Fall back to Telegram's getChat + getChatMemberCount.
     *   3. If anything fails (private channel, network error, no bot token),
     *      return what we DO have so the admin can fill the gaps by hand.
     */
    public function lookupChannel(Request $request, TelegramBotClient $bot): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'max:128']]);
        $raw = trim((string) $request->input('q'));
        if ($raw === '') {
            return response()->json(['error' => 'empty'], 422);
        }

        // Normalise to a username OR a numeric chat id.
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
            return response()->json([
                'username' => $local->username,
                'title' => $local->title,
                'members' => (int) $local->members_count,
                'avatar' => $local->avatar_url,
                'language' => $local->language,
                'telegram_chat_id' => $local->telegram_chat_id,
                'public_url' => $local->public_url,
                'source' => 'catalog',
            ]);
        }

        // 2. Fall back to Telegram's getChat for public channels / bots.
        $chatId = $isNumericChatId ? $raw : '@' . $username;
        $chat = $bot->getChat($chatId);
        if (! is_array($chat) || (empty($chat['username']) && empty($chat['id']))) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Pull member count from a separate call (getChat doesn't return it).
        $members = $bot->getChatMemberCount($chatId);

        // Pull the largest available photo.
        $photoUrl = null;
        if (isset($chat['photo']['big_file_id'])) {
            $file = $bot->getFile($chat['photo']['big_file_id']);
            if ($file !== null && ($file['file_path'] ?? null) !== null) {
                $photoUrl = $bot->fileDownloadUrl($file['file_path']);
            }
        } elseif (isset($chat['photo']['small_file_id'])) {
            $file = $bot->getFile($chat['photo']['small_file_id']);
            if ($file !== null && ($file['file_path'] ?? null) !== null) {
                $photoUrl = $bot->fileDownloadUrl($file['file_path']);
            }
        }

        $resolvedUsername = $chat['username'] ?? $username;
        $resolvedTitle = $chat['title'] ?? $chat['username'] ?? $username;

        return response()->json([
            'username' => $resolvedUsername,
            'title' => $resolvedTitle,
            'members' => $members,
            'avatar' => $photoUrl,
            'language' => 'fa', // default; admin can change in the form
            'telegram_chat_id' => $telegramChatId ?? (isset($chat['id']) ? (string) $chat['id'] : null),
            'public_url' => $resolvedUsername ? 'https://t.me/' . $resolvedUsername : null,
            'source' => 'telegram',
        ]);
    }

    /**
     * Add a suggested channel.
     *
     * Only the username is required — the admin can either click "lookup"
     * (which fills the form fields via JS) or just submit and we'll auto-
     * resolve via Telegram if title/members are blank. This keeps the form
     * forgiving when Telegram's API is rate-limited or unreachable.
     */
    public function storeChannel(Request $request, AuditLogger $audit, TelegramBotClient $bot): RedirectResponse
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

        // Auto-fill missing fields via Telegram when the admin didn't pre-fill them.
        $title = trim((string) ($data['title'] ?? ''));
        $members = isset($data['members_count']) ? (int) $data['members_count'] : null;
        $avatarUrl = null;
        $telegramChatId = null;

        if ($title === '' || $members === null) {
            $chat = $bot->getChat('@' . $username);
            if (is_array($chat)) {
                if ($title === '') {
                    $title = (string) ($chat['title'] ?? $username);
                }
                if ($members === null) {
                    $members = $bot->getChatMemberCount('@' . $username);
                }
                $telegramChatId = isset($chat['id']) ? (string) $chat['id'] : null;
                if (isset($chat['photo']['big_file_id'])) {
                    $file = $bot->getFile($chat['photo']['big_file_id']);
                    if ($file !== null && ($file['file_path'] ?? null) !== null) {
                        $avatarUrl = $bot->fileDownloadUrl($file['file_path']);
                    }
                } elseif (isset($chat['photo']['small_file_id'])) {
                    $file = $bot->getFile($chat['photo']['small_file_id']);
                    if ($file !== null && ($file['file_path'] ?? null) !== null) {
                        $avatarUrl = $bot->fileDownloadUrl($file['file_path']);
                    }
                }
            }
        }

        if ($title === '') {
            $title = $username; // last-resort fallback so the DB column stays non-empty
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

    public function update(Request $request, SuggestedChannel $channel, AuditLogger $audit, TelegramBotClient $bot): RedirectResponse
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
        $avatarUrl = $channel->avatar_url;
        $telegramChatId = $channel->telegram_chat_id;

        // Refresh channel info from Telegram when the admin clicks the
        // "refresh from Telegram" button, OR when title/members are blank.
        $shouldRefresh = ! empty($data['refresh_from_telegram']) || $title === '' || $members === null;
        if ($shouldRefresh) {
            $chat = $bot->getChat('@' . $username);
            if (is_array($chat)) {
                if ($title === '' || ! empty($data['refresh_from_telegram'])) {
                    $title = (string) ($chat['title'] ?? $username);
                }
                if ($members === null || ! empty($data['refresh_from_telegram'])) {
                    $members = $bot->getChatMemberCount('@' . $username);
                }
                $telegramChatId = isset($chat['id']) ? (string) $chat['id'] : $telegramChatId;
                if (isset($chat['photo']['big_file_id'])) {
                    $file = $bot->getFile($chat['photo']['big_file_id']);
                    if ($file !== null && ($file['file_path'] ?? null) !== null) {
                        $avatarUrl = $bot->fileDownloadUrl($file['file_path']);
                    }
                } elseif (isset($chat['photo']['small_file_id'])) {
                    $file = $bot->getFile($chat['photo']['small_file_id']);
                    if ($file !== null && ($file['file_path'] ?? null) !== null) {
                        $avatarUrl = $bot->fileDownloadUrl($file['file_path']);
                    }
                }
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

    /**
     * Permanently delete a suggested channel. Past campaign_targets rows
     * survive because `suggested_channel_id` is nullOnDelete — they keep
     * their snapshot of channel_username / channel_title etc.
     */
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
