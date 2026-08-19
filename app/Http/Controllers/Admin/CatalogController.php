<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\SuggestedChannel;
use App\Models\TargetCategory;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function storeCategory(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title_fa' => ['required', 'string', 'max:100'],
            'title_en' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:100', 'unique:target_categories,slug'],
            'description_fa' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $category = TargetCategory::create([
            ...$data,
            'icon' => $data['icon'] ?? 'folder',
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => true,
        ]);
        $audit->log('catalog.category_created', auth('admin')->user(), $category, after: $data);

        return back()->with('success', 'دسته‌بندی ایجاد شد.');
    }

    /**
     * Update an existing category — locale-aware title, description, icon,
     * sort order, and active toggle. Slug is intentionally NOT editable
     * (it's the public filter key the admin URL uses).
     */
    public function updateCategory(Request $request, TargetCategory $category, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'title_fa' => ['required', 'string', 'max:100'],
            'title_en' => ['required', 'string', 'max:100'],
            'description_fa' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:40'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $before = $category->only(['title_fa', 'title_en', 'description_fa', 'description_en', 'icon', 'sort_order', 'is_active']);
        $category->update([
            'title_fa' => $data['title_fa'],
            'title_en' => $data['title_en'],
            'description_fa' => $data['description_fa'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'icon' => $data['icon'] ?? $category->icon,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);
        $audit->log('catalog.category_updated', auth('admin')->user(), $category, before: $before, after: $category->only(['title_fa', 'title_en', 'description_fa', 'description_en', 'icon', 'sort_order', 'is_active']));

        return back()->with('success', 'دسته‌بندی به‌روزرسانی شد.');
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

    public function storeChannel(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{5,32}$/', 'unique:suggested_channels,username'],
            'title' => ['required', 'string', 'max:150'],
            'public_url' => ['nullable', 'url:http,https', 'max:2048'],
            'language' => ['required', Rule::in(['fa', 'en', 'ar', 'other'])],
            'members_count' => ['required', 'integer', 'min:0'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:target_categories,id'],
            'is_featured' => ['nullable', 'boolean'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach (array_unique($data['category_ids']) as $categoryId) {
            $count = TargetCategory::findOrFail($categoryId)->channels()->wherePivot('target_category_id', $categoryId)->count();
            if ($count >= config('ads-platform.max_channels_per_category', 30)) {
                throw ValidationException::withMessages(['category_ids' => 'یکی از دسته‌ها به سقف 30 کانال رسیده است.']);
            }
        }

        $username = ltrim($data['username'], '@');
        $channel = SuggestedChannel::create([
            'username' => $username,
            'title' => $data['title'],
            'public_url' => 'https://t.me/'.$username,
            'language' => $data['language'],
            'members_count' => $data['members_count'],
            'eligibility_status' => $data['members_count'] > $this->minimumMembers() ? 'eligible' : 'ineligible',
            'is_featured' => $data['is_featured'] ?? false,
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

    public function update(Request $request, SuggestedChannel $channel, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'regex:/^[A-Za-z0-9_]{5,32}$/', Rule::unique('suggested_channels', 'username')->ignore($channel)],
            'title' => ['required', 'string', 'max:150'],
            'language' => ['required', Rule::in(['fa', 'en', 'ar', 'other'])],
            'members_count' => ['required', 'integer', 'min:0'],
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['integer', 'exists:target_categories,id'],
            'is_featured' => ['nullable', 'boolean'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
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

        $before = $channel->only(['username', 'title', 'language', 'members_count', 'is_featured', 'is_active']);
        $username = ltrim($data['username'], '@');
        $channel->update([
            'username' => $username,
            'title' => $data['title'],
            'public_url' => 'https://t.me/'.$username,
            'language' => $data['language'],
            'members_count' => $data['members_count'],
            'eligibility_status' => $data['members_count'] > $this->minimumMembers() ? 'eligible' : 'ineligible',
            'is_featured' => $data['is_featured'] ?? false,
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
            ...$channel->only(['username', 'title', 'language', 'members_count', 'is_featured', 'is_active']),
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
