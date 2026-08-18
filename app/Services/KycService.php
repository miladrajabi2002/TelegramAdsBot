<?php

namespace App\Services;

use App\Enums\KycLevel;
use App\Enums\KycReasonCode;
use App\Enums\KycStatus;
use App\Models\Admin;
use App\Models\FundingCard;
use App\Models\KycApplication;
use App\Models\KycReview;
use DomainException;
use Illuminate\Support\Facades\DB;

final class KycService
{
    public const DOCUMENT_NATIONAL_ID_FRONT = 'national_id_front';

    public const DOCUMENT_SELFIE_WITH_ID = 'selfie_with_id';

    /** @var list<string> */
    public const APPROVAL_CHECKLIST = [
        'phone_verified',
        'national_id_readable',
        'selfie_matches_identity',
        'card_owner_matches_identity',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['submitted'],
        'changes_requested' => ['submitted'],
        'submitted' => ['under_review'],
        'under_review' => ['approved', 'changes_requested', 'rejected_permanent'],
        'approved' => ['revoked'],
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function submit(KycApplication $application): KycApplication
    {
        return $this->withLockedApplication($application, function (KycApplication $locked): void {
            $this->assertTransition($locked->status, KycStatus::Submitted);
            $this->assertSubmissionComplete($locked);

            $before = $locked->status->value;
            $locked->status = KycStatus::Submitted;
            $locked->submitted_at = now();
            $locked->reviewed_at = null;
            $locked->reviewed_by = null;
            $this->saveWithVersionBump($locked);

            $this->auditLogger->log(
                action: 'kyc.submitted',
                actor: $locked->user,
                subject: $locked,
                before: ['status' => $before],
                after: ['status' => KycStatus::Submitted->value, 'version' => $locked->version],
            );
        });
    }

    public function beginReview(KycApplication $application, Admin $admin): KycApplication
    {
        $this->assertReviewAdmin($admin);

        return $this->withLockedApplication($application, function (KycApplication $locked) use ($admin): void {
            $this->assertTransition($locked->status, KycStatus::UnderReview);
            $before = $locked->status->value;

            $locked->status = KycStatus::UnderReview;
            $locked->reviewed_by = $admin->getKey();
            $this->saveWithVersionBump($locked);
            $this->recordReview($locked, $admin, KycStatus::UnderReview);

            $this->auditLogger->log(
                'kyc.review_started',
                $admin,
                $locked,
                ['status' => $before],
                ['status' => KycStatus::UnderReview->value],
            );
        });
    }

    /** @param array<string, bool|string|int|null> $checklist */
    public function approve(
        KycApplication $application,
        Admin $admin,
        FundingCard $approvedCard,
        array $checklist,
        ?string $note = null,
    ): KycApplication {
        $this->assertReviewAdmin($admin);

        return $this->withLockedApplication($application, function (KycApplication $locked) use (
            $admin,
            $approvedCard,
            $checklist,
            $note,
        ): void {
            $this->assertTransition($locked->status, KycStatus::Approved);
            $this->assertApprovalChecklist($checklist);

            $card = FundingCard::query()->lockForUpdate()->findOrFail($approvedCard->getKey());

            if ((int) $card->user_id !== (int) $locked->user_id
                || (int) $card->kyc_application_id !== (int) $locked->getKey()) {
                throw new DomainException('The approved card does not belong to this KYC application.');
            }

            if (! in_array($card->status, ['pending', 'approved'], true)) {
                throw new DomainException('The selected funding card is not eligible for approval.');
            }

            $before = $locked->status->value;
            $card->forceFill([
                'status' => 'approved',
                'verification_method' => $card->verification_method ?: 'admin_review',
                'verification_result' => array_merge($card->verification_result ?? [], [
                    'checklist' => $checklist,
                    'reviewed_by' => $admin->getKey(),
                ]),
                'verified_at' => now(),
            ])->save();

            $locked->forceFill([
                'status' => KycStatus::Approved,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'admin_note' => $note,
            ]);
            $this->saveWithVersionBump($locked);

            $locked->user()->update(['kyc_level' => KycLevel::RialVerified]);
            $this->recordReview($locked, $admin, KycStatus::Approved, null, $note, $checklist);

            $this->auditLogger->log(
                'kyc.approved',
                $admin,
                $locked,
                ['status' => $before, 'kyc_level' => KycLevel::Base->value],
                [
                    'status' => KycStatus::Approved->value,
                    'kyc_level' => KycLevel::RialVerified->value,
                    'funding_card_last4' => $card->last4,
                ],
                $note,
            );
        });
    }

    /** @param array<string, bool|string|int|null> $checklist */
    public function requestChanges(
        KycApplication $application,
        Admin $admin,
        KycReasonCode $reason,
        string $note,
        array $checklist = [],
    ): KycApplication {
        $this->assertReviewAdmin($admin);
        $note = trim($note);

        if ($note === '') {
            throw new DomainException('A correction note is required.');
        }

        return $this->withLockedApplication($application, function (KycApplication $locked) use (
            $admin,
            $reason,
            $note,
            $checklist,
        ): void {
            $this->assertTransition($locked->status, KycStatus::ChangesRequested);
            $before = $locked->status->value;

            if ($reason === KycReasonCode::CardOwnerMismatch) {
                $locked->cards()
                    ->whereIn('status', ['pending', 'changes_requested'])
                    ->update(['status' => 'changes_requested', 'verified_at' => null]);
            }

            $locked->forceFill([
                'status' => KycStatus::ChangesRequested,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'admin_note' => $note,
            ]);
            $this->saveWithVersionBump($locked);
            $this->keepBaseUnlessAnotherApprovalExists($locked);
            $this->recordReview($locked, $admin, KycStatus::ChangesRequested, $reason, $note, $checklist);

            $this->auditLogger->log(
                'kyc.changes_requested',
                $admin,
                $locked,
                ['status' => $before],
                ['status' => KycStatus::ChangesRequested->value, 'reason_code' => $reason->value],
                $note,
            );
        });
    }

    /** @param array<string, bool|string|int|null> $checklist */
    public function rejectPermanently(
        KycApplication $application,
        Admin $admin,
        KycReasonCode $reason,
        string $note,
        array $checklist = [],
    ): KycApplication {
        $this->assertReviewAdmin($admin);
        $note = trim($note);

        if ($note === '') {
            throw new DomainException('A permanent rejection note is required.');
        }

        return $this->withLockedApplication($application, function (KycApplication $locked) use (
            $admin,
            $reason,
            $note,
            $checklist,
        ): void {
            $this->assertTransition($locked->status, KycStatus::RejectedPermanent);
            $before = $locked->status->value;

            $locked->cards()->where('status', 'pending')->update(['status' => 'rejected']);
            $locked->forceFill([
                'status' => KycStatus::RejectedPermanent,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'admin_note' => $note,
            ]);
            $this->saveWithVersionBump($locked);
            $locked->user()->update(['kyc_level' => KycLevel::Restricted]);
            $this->recordReview($locked, $admin, KycStatus::RejectedPermanent, $reason, $note, $checklist);

            $this->auditLogger->log(
                'kyc.rejected_permanently',
                $admin,
                $locked,
                ['status' => $before],
                ['status' => KycStatus::RejectedPermanent->value, 'reason_code' => $reason->value],
                $note,
            );
        });
    }

    public function revoke(
        KycApplication $application,
        Admin $admin,
        KycReasonCode $reason,
        string $note,
    ): KycApplication {
        $this->assertReviewAdmin($admin);
        $note = trim($note);

        if ($note === '') {
            throw new DomainException('A revocation note is required.');
        }

        return $this->withLockedApplication($application, function (KycApplication $locked) use (
            $admin,
            $reason,
            $note,
        ): void {
            $this->assertTransition($locked->status, KycStatus::Revoked);
            $before = $locked->status->value;

            $locked->cards()->where('status', 'approved')->update(['status' => 'revoked']);
            $locked->forceFill([
                'status' => KycStatus::Revoked,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'admin_note' => $note,
            ]);
            $this->saveWithVersionBump($locked);
            $locked->user()->update(['kyc_level' => KycLevel::Restricted]);
            $this->recordReview($locked, $admin, KycStatus::Revoked, $reason, $note);

            $this->auditLogger->log(
                'kyc.revoked',
                $admin,
                $locked,
                ['status' => $before],
                ['status' => KycStatus::Revoked->value, 'reason_code' => $reason->value],
                $note,
            );
        });
    }

    private function assertSubmissionComplete(KycApplication $application): void
    {
        $application->loadMissing('user');

        if ($application->user->phone_verified_at === null) {
            throw new DomainException('A verified phone number is required before submitting KYC.');
        }

        if (trim((string) $application->legal_name_encrypted) === '') {
            throw new DomainException('The legal account-holder name is required.');
        }

        $documentKinds = $application->documents()->pluck('kind');

        foreach ([self::DOCUMENT_NATIONAL_ID_FRONT, self::DOCUMENT_SELFIE_WITH_ID] as $kind) {
            if (! $documentKinds->contains($kind)) {
                throw new DomainException("Required KYC document [{$kind}] is missing.");
            }
        }

        $hasReviewableCard = $application->cards()
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if (! $hasReviewableCard) {
            throw new DomainException('At least one funding card is required.');
        }
    }

    /** @param array<string, bool|string|int|null> $checklist */
    private function assertApprovalChecklist(array $checklist): void
    {
        foreach (self::APPROVAL_CHECKLIST as $check) {
            if (($checklist[$check] ?? null) !== true) {
                throw new DomainException("KYC approval check [{$check}] must be confirmed.");
            }
        }
    }

    private function assertReviewAdmin(Admin $admin): void
    {
        if (! $admin->exists || ! $admin->is_active) {
            throw new DomainException('An active admin is required for KYC review.');
        }

        if (! $admin->hasPermission('kyc.review')) {
            throw new DomainException('The admin is not allowed to review KYC applications.');
        }
    }

    private function assertTransition(KycStatus $from, KycStatus $to): void
    {
        if (! in_array($to->value, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true)) {
            throw new DomainException("Invalid KYC transition [{$from->value} -> {$to->value}].");
        }
    }

    /**
     * @param  callable(KycApplication): void  $callback
     */
    private function withLockedApplication(KycApplication $application, callable $callback): KycApplication
    {
        if (! $application->exists) {
            throw new DomainException('KYC application must be persisted.');
        }

        $expectedLockVersion = (int) $application->lock_version;

        return DB::transaction(function () use ($application, $expectedLockVersion, $callback): KycApplication {
            $locked = KycApplication::query()->lockForUpdate()->findOrFail($application->getKey());

            if ((int) $locked->lock_version !== $expectedLockVersion) {
                throw new DomainException('The KYC application was changed by another reviewer.');
            }

            $callback($locked);

            return $locked->refresh();
        }, 3);
    }

    private function saveWithVersionBump(KycApplication $application): void
    {
        $application->lock_version = (int) $application->lock_version + 1;
        $application->save();
    }

    /** @param array<string, bool|string|int|null> $checklist */
    private function recordReview(
        KycApplication $application,
        Admin $admin,
        KycStatus $decision,
        ?KycReasonCode $reason = null,
        ?string $note = null,
        array $checklist = [],
    ): void {
        KycReview::create([
            'kyc_application_id' => $application->getKey(),
            'admin_id' => $admin->getKey(),
            'decision' => $decision->value,
            'reason_code' => $reason?->value,
            'note' => $note,
            'checklist' => $checklist,
        ]);
    }

    private function keepBaseUnlessAnotherApprovalExists(KycApplication $application): void
    {
        $hasAnotherApproval = KycApplication::query()
            ->where('user_id', $application->user_id)
            ->whereKeyNot($application->getKey())
            ->where('status', KycStatus::Approved->value)
            ->exists();

        if (! $hasAnotherApproval) {
            $application->user()->update(['kyc_level' => KycLevel::Base]);
        }
    }
}
