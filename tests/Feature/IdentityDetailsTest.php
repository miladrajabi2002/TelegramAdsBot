<?php

namespace Tests\Feature;

use App\Enums\KycLevel;
use App\Enums\KycStatus;
use App\Models\FundingCard;
use App\Models\KycApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IdentityDetailsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function verified_user_sees_their_full_identity_and_card_details(): void
    {
        $user = User::factory()->create([
            'locale' => 'fa',
            'phone' => '+989121234567',
            'phone_verified_at' => now(),
            'kyc_level' => KycLevel::RialVerified,
        ]);

        $application = KycApplication::create([
            'user_id' => $user->getKey(),
            'version' => 1,
            'status' => KycStatus::Approved,
            'legal_name_encrypted' => 'آرمان احمدی',
            'legal_name_search' => 'آرمان احمدی',
            'national_id_encrypted' => '0013547869',
            'national_id_hmac' => hash_hmac('sha256', '0013547869', (string) config('ads-platform.kyc_hmac_key')),
            'submitted_at' => now()->subHour(),
            'reviewed_at' => now(),
            'lock_version' => 1,
        ]);

        FundingCard::create([
            'user_id' => $user->getKey(),
            'kyc_application_id' => $application->getKey(),
            'pan_encrypted' => '6037997512345678',
            'pan_hmac' => hash_hmac('sha256', '6037997512345678', (string) config('ads-platform.kyc_hmac_key')),
            'bin' => '603799',
            'last4' => '5678',
            'holder_name_encrypted' => 'آرمان احمدی',
            'holder_name_search' => 'آرمان احمدی',
            'status' => 'approved',
            'verification_method' => 'admin_review',
            'verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('app.identity.show'))
            ->assertOk()
            ->assertSee('0013547869')
            ->assertSee('6037997512345678')
            ->assertDontSee('001******869')
            ->assertDontSee('•••• •••• •••• 5678');
    }
}
