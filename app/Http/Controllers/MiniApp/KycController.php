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
            'card_number' => ['required', 'string', 'max:30'],
            'national_id_image' => [$canReuseDocuments ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'selfie_with_id_image' => [$canReuseDocuments ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'برای ثبت درخواست، موافقت با پردازش اطلاعات احراز هویت الزامی است.',
            'national_id_image.required' => 'تصویر کارت ملی را بارگذاری کنید.',
            'selfie_with_id_image.required' => 'تصویر شخص همراه کارت ملی را بارگذاری کنید.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (! IranianIdentity::validNationalId((string) $request->input('national_id'))) {
                $validator->errors()->add('national_id', 'کد ملی معتبر نیست.');
            }
            if (! IranianIdentity::validCard((string) $request->input('card_number'))) {
                $validator->errors()->add('card_number', 'شماره کارت بانکی معتبر نیست.');
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

        $storedFiles = [];
        try {
            foreach (['national_id_image', 'selfie_with_id_image'] as $field) {
                if ($request->hasFile($field)) {
                    $storedFiles[$field] = $storage->storeKyc($request->file($field), $user);
                }
            }

            $application = DB::transaction(function () use ($user, $data, $nationalId, $nationalIdHmac, $pan, $panHmac, $previous, $storedFiles, $kycService): KycApplication {
                $version = ((int) $user->kycApplications()->max('version')) + 1;
                $application = KycApplication::create([
                    'user_id' => $user->getKey(),
                    'version' => $version,
                    'status' => KycStatus::Draft,
                    'legal_name_encrypted' => trim($data['legal_name']),
                    'legal_name_search' => mb_strtolower(trim($data['legal_name'])),
                    'national_id_encrypted' => $nationalId,
                    'national_id_hmac' => $nationalIdHmac,
                    'submitted_at' => null,
                ]);

                FundingCard::updateOrCreate(
                    ['pan_hmac' => $panHmac],
                    [
                        'user_id' => $user->getKey(),
                        'kyc_application_id' => $application->getKey(),
                        'pan_encrypted' => $pan,
                        'bin' => substr($pan, 0, 6),
                        'last4' => substr($pan, -4),
                        'holder_name_encrypted' => trim($data['legal_name']),
                        'holder_name_search' => mb_strtolower(trim($data['legal_name'])),
                        'status' => 'pending',
                        'verification_method' => 'admin_review',
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
