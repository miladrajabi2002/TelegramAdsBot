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
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
        $category = TargetCategory::create([...$data, 'is_active' => true]);
        $audit->log('catalog.category_created', auth('admin')->user(), $category, after: $data);

        return back()->with('success', 'دسته‌بندی ایجاد شد.');
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
                throw ValidationException::withMessages(['category_ids' => 'یکی از دسته‌ها به سقف ۳۰ کانال رسیده است.']);
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
                throw ValidationException::withMessages(['category_ids' => 'یکی از دسته‌ها به سقف ۳۰ کانال رسیده است.']);
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

    private function minimumMembers(): int
    {
        $setting = Setting::where('key', 'minimum_channel_members')->first();

        return max(1000, (int) data_get($setting?->value, 'value', config('ads-platform.minimum_target_members', 1000)));
    }
}
