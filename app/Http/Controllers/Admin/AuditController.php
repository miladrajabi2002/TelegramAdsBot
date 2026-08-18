<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(Request $request): View
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $logs = AuditLog::query()->with(['actor', 'subject'])
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', '%'.trim((string) $request->input('action')).'%'))
            ->when($request->filled('actor_type'), fn ($q) => $q->where('actor_type', $request->input('actor_type')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = trim((string) $request->input('q'));
                $query->where(fn ($nested) => $nested->where('action', 'like', "%{$term}%")
                    ->orWhere('correlation_id', 'like', "%{$term}%")
                    ->orWhere('reason', 'like', "%{$term}%")
                    ->orWhere('subject_type', 'like', "%{$term}%")
                    ->orWhere('actor_type', 'like', "%{$term}%"));
            })
            ->when($request->filled('date'), function ($query) use ($request): void {
                $timezone = (string) config('ads-platform.display_timezone', 'Asia/Tehran');
                $from = \Illuminate\Support\Carbon::parse($request->input('date'), $timezone)->startOfDay()->utc();
                $to = \Illuminate\Support\Carbon::parse($request->input('date'), $timezone)->endOfDay()->utc();
                $query->whereBetween('created_at', [$from, $to]);
            })
            ->latest()->paginate(40)->withQueryString();

        return view('admin.audit.index', compact('logs'));
    }
}
