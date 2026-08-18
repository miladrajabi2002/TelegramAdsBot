<?php

namespace Tests\Unit;

use App\Enums\KycLevel;
use App\Enums\KycReasonCode;
use App\Enums\KycStatus;
use App\Models\Admin;
use App\Models\FundingCard;
use App\Models\KycApplication;
use App\Models\KycDocument;
use App\Models\PrivateFile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KycService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KycServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_complete_application_can_be_submitted_reviewed_and_approved(): void
    {
        [$application, $card, $user] = $this->completeApplication();
        $admin = $this->admin();
        $service = new KycService(new AuditLogger);

        $application = $service->submit($application);
        $this->assertSame(KycStatus::Submitted, $application->status);

        $application = $service->beginReview($application, $admin);
        $this->assertSame(KycStatus::UnderReview, $application->status);

        $application = $service->approve(
            $application,
            $admin,
            $card,
            array_fill_keys(KycService::APPROVAL_CHECKLIST, true),
            'Identity and funding card checked.',
        );

        $this->assertSame(KycStatus::Approved, $application->status);
        $this->assertSame(KycLevel::RialVerified, $user->refresh()->kyc_level);
        $this->assertSame('approved', $card->refresh()->status);
        $this->assertNotNull($card->verified_at);
        $this->assertDatabaseHas('kyc_reviews', [
            'kyc_application_id' => $application->getKey(),
            'decision' => KycStatus::Approved->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.approved']);
    }

    public function test_card_owner_mismatch_requests_changes_and_keeps_base_level(): void
    {
        [$application, $card, $user] = $this->completeApplication();
        $admin = $this->admin();
        $service = new KycService(new AuditLogger);
        $application = $service->beginReview($service->submit($application), $admin);

        $application = $service->requestChanges(
            $application,
            $admin,
            KycReasonCode::CardOwnerMismatch,
            'Submit a bank card owned by the verified identity.',
        );

        $this->assertSame(KycStatus::ChangesRequested, $application->status);
        $this->assertSame(KycLevel::Base, $user->refresh()->kyc_level);
        $this->assertSame('changes_requested', $card->refresh()->status);
        $this->assertDatabaseHas('kyc_reviews', [
            'reason_code' => KycReasonCode::CardOwnerMismatch->value,
        ]);
    }

    public function test_approval_requires_every_explicit_check_and_a_card_from_the_same_application(): void
    {
        [$application, $card] = $this->completeApplication();
        $admin = $this->admin();
        $service = new KycService(new AuditLogger);
        $application = $service->beginReview($service->submit($application), $admin);
        $checklist = array_fill_keys(KycService::APPROVAL_CHECKLIST, true);
        $checklist['card_owner_matches_identity'] = false;

        $this->expectException(DomainException::class);
        $service->approve($application, $admin, $card, $checklist);
    }

    public function test_incomplete_application_cannot_be_submitted(): void
    {
        [$application] = $this->completeApplication(includeSelfie: false);
        $service = new KycService(new AuditLogger);

        $this->expectException(DomainException::class);
        $service->submit($application);
    }

    public function test_stale_application_instance_cannot_overwrite_a_newer_review(): void
    {
        [$application] = $this->completeApplication();
        $service = new KycService(new AuditLogger);
        $stale = KycApplication::query()->findOrFail($application->getKey());

        $service->submit($application);

        $this->expectException(DomainException::class);
        $service->submit($stale);
    }

    /** @return array{KycApplication, FundingCard, User} */
    private function completeApplication(bool $includeSelfie = true): array
    {
        $user = User::create([
            'telegram_user_id' => 201,
            'display_name' => 'KYC User',
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
            'kyc_level' => KycLevel::Base,
        ]);
        $application = KycApplication::create([
            'user_id' => $user->getKey(),
            'version' => 1,
            'status' => KycStatus::Draft,
            'legal_name_encrypted' => 'Test Account Holder',
            'legal_name_search' => 'test account holder',
            'national_id_encrypted' => '0012345678',
            'national_id_hmac' => hash_hmac('sha256', '0012345678', 'test-key'),
            'lock_version' => 1,
        ]);

        $kinds = [KycService::DOCUMENT_NATIONAL_ID_FRONT];
        if ($includeSelfie) {
            $kinds[] = KycService::DOCUMENT_SELFIE_WITH_ID;
        }

        foreach ($kinds as $index => $kind) {
            $file = PrivateFile::create([
                'owner_user_id' => $user->getKey(),
                'disk' => 'local',
                'storage_key' => "kyc/test-{$kind}",
                'original_name' => "document-{$index}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1000,
                'sha256' => hash('sha256', $kind),
                'is_encrypted' => true,
            ]);
            KycDocument::create([
                'kyc_application_id' => $application->getKey(),
                'private_file_id' => $file->getKey(),
                'kind' => $kind,
            ]);
        }

        $card = FundingCard::create([
            'user_id' => $user->getKey(),
            'kyc_application_id' => $application->getKey(),
            'pan_encrypted' => '6037997512345678',
            'pan_hmac' => hash_hmac('sha256', '6037997512345678', 'test-key'),
            'bin' => '603799',
            'last4' => '5678',
            'holder_name_encrypted' => 'Test Account Holder',
            'holder_name_search' => 'test account holder',
            'status' => 'pending',
        ]);

        return [$application, $card, $user];
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Compliance Admin',
            'email' => 'compliance@example.test',
            'password' => 'password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
