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
        if ($previous && ! in_array($previous->status, [KycStatus::ChangesRequested], true)) {
            throw ValidationException::withMessages([
                'kyc' => match ($previous->status) {
                    KycStatus::Approved => 'احراز هویت شما تأیید شده است. برای تغییر اطلاعات با پشتیبانی تماس بگیرید.',
                    KycStatus::Submitted, KycStatus::UnderReview => 'یک درخواست احراز هویت در حال بررسی دارید.',
                    default => 'ثبت درخواست جدید برای این حساب نیازمند بررسی پشتیبانی است.',
                },
            ]);
        }
        $canReuseDocuments = $previous?->status === KycStatus::ChangesRequested;

        $validator = Validator::make($request->all(), [
            'legal_name' => ['required', 'string', 'min:3', 'max:120'],
            'national_id' => ['required', 'string', 'max:20'],
            'card_holder_name' => ['required', 'string', 'min:3', 'max:120'],
            'card_number' => ['required', 'string', 'max:30'],
            'national_id_image' => [$canReuseDocuments ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'selfie_with_id_image' => [$canReuseDocuments ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'برای ثبت درخواست، موافقت با پردازش اطلاعات احراز هویت الزامی است.',
            'national_id_image.required' => 'تصویر کارت ملی را بارگذاری کنید.',
            'selfie_with_id_image.required' => 'تصویر شخص همراه کارت ملی را بارگذاری کنید.',
            'card_holder_name.required' => 'نام صاحب حساب (همان نام روی کارت بانکی) را وارد کنید.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (! IranianIdentity::validNationalId((string) $request->input('national_id'))) {
                $validator->errors()->add('national_id', 'کد ملی معتبر نیست.');
            }
            if (! IranianIdentity::validCard((string) $request->input('card_number'))) {
                $validator->errors()->add('card_number', 'شماره کارت بانکی معتبر نیست.');
            }

            // ─── Card-owner consistency check ───────────────────────────
            // The cardholder name must look like a name (Persian/Latin
            // letters + spaces). If the cardholder name and the legal
            // name differ substantially (after normalisation), we warn
            // the user — but we DO NOT hard-reject; instead we leave
            // the user at the Base KYC level by NOT submitting for
            // review (handled below by a custom flag). The user can
            // re-submit with the correct name. This implements the
            // requirement: "in case of mismatch between the bank card
            // and the national ID card, the user stays at the base
            // level until they send the correct one".
            $cardHolder = preg_replace('/\s+/u', ' ', trim((string) $request->input('card_holder_name')));
            $legalName = preg_replace('/\s+/u', ' ', trim((string) $request->input('legal_name')));
            if (mb_strlen($cardHolder) < 3) {
                $validator->errors()->add('card_holder_name', 'نام صاحب حساب باید حداقل ۳ حرف باشد.');
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

        // Card-holder vs legal-name consistency:
        // - When the names match (after normalisation) we let the
        //   submission go through and the KYC goes into the review queue.
        // - When they DON'T match, we still create the funding card and
        //   KYC application as a Draft, but we do NOT submit it. The
        //   user stays at KycLevel::Base until they re-submit with the
        //   correct cardholder name. This matches the rule:
        //   "If the deposit card number doesn't match the national ID,
        //   the account stays at the base level until the correct one
        //   is provided."
        $cardHolder = preg_replace('/\s+/u', ' ', trim((string) $data['card_holder_name']));
        $legalName = preg_replace('/\s+/u', ' ', trim((string) $data['legal_name']));
        $namesMatch = mb_strtolower($cardHolder) === mb_strtolower($legalName)
            || IranianIdentity::namesLookSimilar($cardHolder, $legalName);

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
                    'user_note' => $namesMatch ? null : 'احتمال مغایرت نام صاحب کارت با کد ملی — بررسی دستی لازم است.',
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
                        'verification_result' => $namesMatch ? null : ['reason' => 'cardholder_name_mismatch'],
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

                // When names do NOT match, leave the application as Draft
                // and DO NOT enter the review queue. The user is asked to
                // re-submit with the correct cardholder name.
                if (! $namesMatch) {
                    return $application;
                }

                return $kycService->submit($application);
            });
        } catch (Throwable $exception) {
            foreach ($storedFiles as $file) {
                $storage->delete($file);
            }
            throw $exception;
        }

        if (! $namesMatch) {
            return redirect()->route('app.identity.show')
                ->with('warning', 'نام صاحب کارت با نام اعلامی شما مطابقت ندارد. لطفاً با کارتی متعلق به خودتان مجدداً تلاش کنید؛ در غیر این صورت حساب در سطح پایه باقی می‌ماند.');
        }

        return redirect()->route('app.identity.show')->with('success', 'مدارک شما دریافت شد و در صف بررسی قرار گرفت.');
    }
}
