<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycReasonCode;
use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\FundingCard;
use App\Models\KycApplication;
use App\Models\KycDocument;
use App\Services\AuditLogger;
use App\Services\KycService;
use App\Services\PrivateDocumentStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class KycController extends Controller
{
    public function index(Request $request): View
    {
        $applications = KycApplication::query()->with(['user', 'cards', 'reviewer'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('q'), function ($q) use ($request): void {
                $term = trim((string) $request->input('q'));
                $q->whereHas('user', fn ($user) => $user->where('display_name', 'like', "%{$term}%")
                    ->orWhere('telegram_username', 'like', "%{$term}%")
                    ->orWhere('telegram_user_id', $term)
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->orderByRaw("CASE WHEN status = 'submitted' THEN 0 WHEN status = 'under_review' THEN 1 ELSE 2 END")
            ->latest('submitted_at')->paginate(25)->withQueryString();

        return view('admin.kyc.index', compact('applications'));
    }

    public function show(KycApplication $application): View
    {
        $application->load(['user.kycApplications', 'documents.file', 'cards', 'reviews.admin']);
        $reasonCodes = KycReasonCode::cases();
        $documentUrls = [];
        foreach ($application->documents as $document) {
            $url = route('admin.kyc.document', ['application' => $application, 'document' => $document]);
            $documentUrls[$document->kind] = $url;
            if ($document->kind === KycService::DOCUMENT_NATIONAL_ID_FRONT) {
                $documentUrls['national_card_front'] = $url;
            }
            if ($document->kind === KycService::DOCUMENT_SELFIE_WITH_ID) {
                $documentUrls['selfie_with_card'] = $url;
            }
        }

        return view('admin.kyc.show', compact('application', 'reasonCodes', 'documentUrls'));
    }

    public function decision(
        Request $request,
        KycApplication $application,
        KycService $service,
        AuditLogger $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'changes_requested', 'rejected_permanent', 'manual_attention'])],
            'card_id' => ['nullable', 'integer', Rule::exists('funding_cards', 'id')->where('kyc_application_id', $application->id)],
            'reason_code' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:2000'],
            'checklist' => ['nullable', 'array'],
        ]);

        $admin = auth('admin')->user();
        if ($application->status === KycStatus::Submitted) {
            $application = $service->beginReview($application, $admin);
        }

        $checklist = collect(KycService::APPROVAL_CHECKLIST)->mapWithKeys(function (string $key) use ($request): array {
            return [$key => $request->boolean("checklist.{$key}") || $request->boolean($key)];
        })->all();

        if ($data['decision'] === 'approved') {
            $card = $application->cards()->when($data['card_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->first();
            if (! $card) {
                throw ValidationException::withMessages(['card_id' => 'کارت بانکی این درخواست را انتخاب کنید.']);
            }
            $service->approve($application, $admin, $card, $checklist, $data['note'] ?? null);
            SendTelegramMessage::dispatch($application->user->telegram_user_id, 'احراز هویت شما تأیید شد و پرداخت ریالی فعال است.');

            return back()->with('success', 'احراز هویت و کارت بانکی تأیید شد.');
        }

        if ($data['decision'] === 'manual_attention') {
            $note = trim((string) ($data['note'] ?? ''));
            $application->update(['admin_note' => $note !== '' ? $note : $application->admin_note]);
            $audit->log('kyc.manual_attention', $admin, $application, after: ['note' => $note]);

            return back()->with('success', 'پرونده در وضعیت بررسی باقی ماند و یادداشت ثبت شد.');
        }

        $reason = KycReasonCode::tryFrom((string) ($data['reason_code'] ?? ''));
        if (! $reason) {
            throw ValidationException::withMessages(['reason_code' => 'دلیل معتبر را انتخاب کنید.']);
        }
        $note = trim((string) ($data['note'] ?? ''));
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['note' => 'دلیل و روش اصلاح را روشن بنویسید.']);
        }

        if ($data['decision'] === 'changes_requested') {
            $service->requestChanges($application, $admin, $reason, $note, $checklist);
            SendTelegramMessage::dispatch($application->user->telegram_user_id, 'مدارک احراز هویت نیازمند اصلاح است. دلیل: '.htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

            return back()->with('success', 'درخواست اصلاح برای کاربر ثبت شد.');
        }

        $service->rejectPermanently($application, $admin, $reason, $note, $checklist);
        SendTelegramMessage::dispatch($application->user->telegram_user_id, 'درخواست احراز هویت قابل تأیید نبود. برای بررسی بیشتر از بخش پشتیبانی پیام بدهید.');

        return back()->with('success', 'رد نهایی درخواست ثبت شد.');
    }

    public function claim(KycApplication $application, KycService $service): RedirectResponse
    {
        $service->beginReview($application, auth('admin')->user());

        return back()->with('success', 'بررسی به نام شما ثبت شد.');
    }

    public function approve(Request $request, KycApplication $application, KycService $service): RedirectResponse
    {
        $data = $request->validate([
            'card_id' => ['required', 'integer', Rule::exists('funding_cards', 'id')->where('kyc_application_id', $application->id)],
            'phone_verified' => ['accepted'],
            'national_id_readable' => ['accepted'],
            'selfie_matches_identity' => ['accepted'],
            'card_owner_matches_identity' => ['accepted'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $checklist = collect(['phone_verified', 'national_id_readable', 'selfie_matches_identity', 'card_owner_matches_identity'])
            ->mapWithKeys(fn ($key) => [$key => $request->boolean($key)])->all();

        $service->approve($application, auth('admin')->user(), FundingCard::findOrFail($data['card_id']), $checklist, $data['note'] ?? null);
        SendTelegramMessage::dispatch($application->user->telegram_user_id, 'احراز هویت شما تأیید شد و پرداخت ریالی فعال است.');

        return redirect()->route('admin.kyc.show', $application)->with('success', 'احراز هویت تأیید و پرداخت ریالی فعال شد.');
    }

    public function requestChanges(Request $request, KycApplication $application, KycService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', Rule::enum(KycReasonCode::class)],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $service->requestChanges($application, auth('admin')->user(), KycReasonCode::from($data['reason_code']), $data['note']);
        SendTelegramMessage::dispatch($application->user->telegram_user_id, 'مدارک احراز هویت نیازمند اصلاح است. دلیل: '.htmlspecialchars($data['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return redirect()->route('admin.kyc.show', $application)->with('success', 'درخواست اصلاح برای کاربر ثبت شد.');
    }

    public function reject(Request $request, KycApplication $application, KycService $service): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', Rule::enum(KycReasonCode::class)],
            'note' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $service->rejectPermanently($application, auth('admin')->user(), KycReasonCode::from($data['reason_code']), $data['note']);
        SendTelegramMessage::dispatch($application->user->telegram_user_id, 'درخواست احراز هویت قابل تأیید نبود. برای بررسی بیشتر از بخش پشتیبانی پیام بدهید.');

        return redirect()->route('admin.kyc.show', $application)->with('success', 'درخواست رد شد و حساب برای پرداخت ریالی محدود ماند.');
    }

    public function document(
        KycApplication $application,
        KycDocument $document,
        PrivateDocumentStorage $storage,
        AuditLogger $audit,
    ): Response {
        abort_unless($document->kyc_application_id === $application->id, 404);
        $admin = auth('admin')->user();
        abort_unless($admin->hasPermission('kyc.view_documents') || $admin->role === 'super_admin', 403);
        $document->load('file');
        $audit->log('kyc.document_viewed', $admin, $application, after: ['document_kind' => $document->kind]);

        return response($storage->read($document->file), 200, [
            'Content-Type' => $document->file->mime_type,
            'Content-Disposition' => 'inline; filename="kyc-document-'.$document->id.'"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
