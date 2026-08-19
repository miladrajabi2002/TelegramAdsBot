<?php

namespace App\Http\Controllers\MiniApp;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Models\KycApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PageController extends Controller
{
    public function help(): View
    {
        $policies = DB::table('policy_versions')->where('is_active', true)->get();

        return view('app.help', compact('policies'));
    }

    public function account(Request $request): View
    {
        $user = $request->user();

        // Load the user's most-recent APPROVED KYC application so the
        // account page can show a brief "verified identity" summary
        // (legal name + masked national ID) after approval. We only
        // load APPROVED applications — pending/draft ones don't have
        // verified info worth showing on the account page (they're
        // shown on the identity page instead).
        $approvedKyc = KycApplication::query()
            ->where('user_id', $user->getKey())
            ->where('status', KycStatus::Approved)
            ->latest('version')
            ->first();

        return view('app.account', ['approvedKyc' => $approvedKyc]);
    }

    public function legal(Request $request, string $type): View
    {
        $allowed = ['service_terms', 'privacy_kyc', 'ads_policy'];
        abort_unless(in_array($type, $allowed, true), 404);

        $locale = in_array($request->query('lang'), ['fa', 'en'], true)
            ? (string) $request->query('lang')
            : (str_starts_with((string) $request->getPreferredLanguage(['fa', 'en']), 'en') ? 'en' : 'fa');
        app()->setLocale($locale);
        $policy = DB::table('policy_versions')
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
        abort_if($policy === null, 404);

        $title = (string) data_get($policy, 'title_'.$locale);
        $content = (string) data_get($policy, 'content_'.$locale);
        $description = Str::limit(preg_replace('/\s+/u', ' ', $content) ?? $content, 160);

        return view('legal.show', compact('policy', 'type', 'locale', 'title', 'content', 'description'));
    }
}
