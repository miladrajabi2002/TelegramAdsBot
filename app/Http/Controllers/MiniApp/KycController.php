<?php

namespace App\Http\Controllers\MiniApp;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Models\FundingCard;
use App\Models\KycApplication;
use App\Models\KycDocument;
use App\Services\KycService;
use App\Services\PrivateDocumentStorage;
use App\Support\IranianIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class KycController extends Controller
{
    public function show(Request $request): View
    {
        $application = $request->user()->kycApplications()
            ->with(['documents', 'cards', 'reviews'])->latest('version')->first();
        $kycApplication = $application;
        $fundingCards = $request->user()->fundingCards()->latest()->get();

        return view('app.identity.show', compact('application', 'kycApplication', 'fundingCards'));
    }

    public function store(
        Request $request,
        PrivateDocumentStorage $storage,
        KycService $kycService,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user->phone_verified_at) {
            throw ValidationException::withMessages([
                'phone' => 'ابتدا شماره همراه خود را از طریق دکمه رسمی اشتراک شماره در تلگرام تأیید کنید.',
            ]);
        }

        $previous = $user->kycApplications()->with('documents.file')->latest('version')->first();
        // ─── When is the user allowed to submit a new KYC application? ────
        //   • No previous application at all — first time, always allowed.
        //   • Previous is Draft — the user started but never actually
        //     submitted. They should be able to either continue or
        //     replace the draft with a fresh submission. Treating Draft
        //     like "allowed" prevents the confusing "ثبت درخواست جدید
        //     برای این حساب نیازمند بررسی پشتیبانی است" error when a
        //     stale draft is left over in the DB.
        //   • Previous is ChangesRequested — admin asked for corrections;
        //     the user is explicitly allowed to re-submit.
        //
        // In all other cases (Submitted, UnderReview, Approved,
        // RejectedPermanent, Revoked) we block re-submission with a
        // specific Persian error explaining what they need to do.
        if ($previous && ! in_array($previous->status, [KycStatus::Draft, KycStatus::ChangesRequested], true)) {
            throw ValidationException::withMessages([
                'kyc' => match ($previous->status) {
                    KycStatus::Approved => 'احراز هویت شما تأیید شده است. برای تغییر اطلاعات با پشتیبانی تماس بگیرید.',
                    KycStatus::Submitted, KycStatus::UnderReview => 'یک درخواست احراز هویت در حال بررسی دارید. تا پایان بررسی منتظر بمانید.',
                    KycStatus::RejectedPermanent, KycStatus::Revoked => 'احراز هویت شما رد شده است. برای ارسال مجدد، با پشتیبانی تماس بگیرید.',
                    default => 'ثبت درخواست جدید برای این حساب نیازمند بررسی پشتیبانی است.',
                },
            ]);
        }
        // Re-use uploaded documents only when the admin explicitly asked
        // for corrections (so the user doesn't have to re-upload their
        // national ID + selfie). For a fresh Draft we DO require new
        // uploads, because the previous draft might have been abandoned
        // mid-upload and we can't trust its files.
        $canReuseDocuments = $previous?->status === KycStatus::ChangesRequested;

        $validator = Validator::make($request->all(), [
            'legal_name' => ['required', 'string', 'min:3', 'max:120'],
            'national_id' => ['required', 'string', 'max:20'],
            'card_number' => ['required', 'string', 'max:30'],
            'national_id_image' => [$canReuseDocuments ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'selfie_with_id_image' => [$canReuseDocuments ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'برای ثبت درخواست، موافقت با پردازش اطلاعات احراز هویت الزامی است.',
            'national_id_image.required' => 'تصویر کارت ملی را بارگذاری کنید.',
            'selfie_with_id_image.required' => 'تصویر شخص همراه کارت ملی را بارگذاری کنید.',
            'legal_name.required' => 'نام و نام خانوادگی صاحب حساب را وارد کنید (دقیقاً همان نامی که روی کارت بانکی نوشته شده است).',
            'legal_name.min' => 'نام و نام خانوادگی باید حداقل ۳ حرف باشد.',
            'national_id.required' => 'کد ملی را وارد کنید.',
            'card_number.required' => 'شماره کارت بانکی را وارد کنید.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (! IranianIdentity::validNationalId((string) $request->input('national_id'))) {
                $validator->errors()->add('national_id', 'کد ملی معتبر نیست. کد ملی باید ۱۰ رقم و مطابق با کارت ملی باشد.');
            }
            if (! IranianIdentity::validCard((string) $request->input('card_number'))) {
                $validator->errors()->add('card_number', 'شماره کارت بانکی معتبر نیست. شماره کارت باید ۱۶ رقم و مطابق با کارت بانکی شما باشد.');
            }
        });

        $data = $validator->validate();
        $nationalId = preg_replace('/\D/', '', IranianIdentity::digits($data['national_id'])) ?? '';
        $pan = preg_replace('/\D/', '', IranianIdentity::digits($data['card_number'])) ?? '';
        $hmacKey = (string) config('ads-platform.kyc_hmac_key');
        abort_if($hmacKey === '', 503, 'KYC blind-index key is not configured.');
        $nationalIdHmac = hash_hmac('sha256', $nationalId, $hmacKey);
        $panHmac = hash_hmac('sha256', $pan, $hmacKey);

        if (KycApplication::where('national_id_hmac', $nationalIdHmac)->where('user_id', '!=', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['national_id' => 'این مشخصات هویتی قبلاً در حساب دیگری ثبت شده است.']);
        }
        if (FundingCard::where('pan_hmac', $panHmac)->where('user_id', '!=', $user->getKey())->exists()) {
            throw ValidationException::withMessages(['card_number' => 'این کارت بانکی قبلاً در حساب دیگری ثبت شده است.']);
        }

        // The card holder name is the SAME as the legal name (we removed
        // the separate `card_holder_name` field from the form). The card
        // must still belong to the same person who owns the account.
        $cardHolder = preg_replace('/\s+/u', ' ', trim((string) $data['legal_name']));
        $legalName = preg_replace('/\s+/u', ' ', trim((string) $data['legal_name']));
        $namesMatch = true; // by construction — same field

        $storedFiles = [];
        try {
            foreach (['national_id_image', 'selfie_with_id_image'] as $field) {
                if ($request->hasFile($field)) {
                    $storedFiles[$field] = $storage->storeKyc($request->file($field), $user);
                }
            }

            $application = DB::transaction(function () use ($user, $data, $nationalId, $nationalIdHmac, $pan, $panHmac, $previous, $storedFiles, $kycService, $cardHolder, $namesMatch): KycApplication {
                $version = ((int) $user->kycApplications()->max('version')) + 1;
                $application = KycApplication::create([
                    'user_id' => $user->getKey(),
                    'version' => $version,
                    'status' => KycStatus::Draft,
                    'legal_name_encrypted' => trim($data['legal_name']),
                    'legal_name_search' => mb_strtolower(trim($data['legal_name'])),
                    'national_id_encrypted' => $nationalId,
                    'national_id_hmac' => $nationalIdHmac,
                    'user_note' => null,
                    'submitted_at' => null,
                    // Set explicitly so the in-memory model matches the DB
                    // default (1). Without this, KycService::submit() reads
                    // null from the model, casts to (int)0, and compares to
                    // the DB value of 1 → "changed by another reviewer" false
                    // positive on a brand-new application.
                    'lock_version' => 1,
                ]);

                // Re-hydrate from DB so attributes populated by DB defaults
                // (lock_version, timestamps) match what the locking helper
                // will see when it re-fetches the row.
                $application->refresh();

                FundingCard::updateOrCreate(
                    ['pan_hmac' => $panHmac],
                    [
                        'user_id' => $user->getKey(),
                        'kyc_application_id' => $application->getKey(),
                        'pan_encrypted' => $pan,
                        'bin' => substr($pan, 0, 6),
                        'last4' => substr($pan, -4),
                        'holder_name_encrypted' => trim($cardHolder),
                        'holder_name_search' => mb_strtolower(trim($cardHolder)),
                        'status' => 'pending',
                        'verification_method' => 'admin_review',
                        'verification_result' => null,
                        'verified_at' => null,
                    ],
                );

                $mapping = [
                    'national_id_image' => 'national_id_front',
                    'selfie_with_id_image' => 'selfie_with_id',
                ];

                foreach ($mapping as $field => $kind) {
                    $file = $storedFiles[$field] ?? $previous?->documents->firstWhere('kind', $kind)?->file;
                    if ($file) {
                        KycDocument::create([
                            'kyc_application_id' => $application->getKey(),
                            'private_file_id' => $file->getKey(),
                            'kind' => $kind,
                        ]);
                    }
                }

                return $kycService->submit($application);
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $file) {
                $storage->delete($file);
            }
            throw $exception;
        }

        return redirect()->route('app.identity.show')->with('success', 'مدارک شما دریافت شد و در صف بررسی قرار گرفت.');
    }
}
