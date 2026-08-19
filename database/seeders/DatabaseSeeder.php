<?php

namespace Database\Seeders;

use App\Enums\KycLevel;
use App\Enums\KycStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Admin;
use App\Models\CampaignMetricSnapshot;
use App\Models\FundingCard;
use App\Models\KycApplication;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\PricingRule;
use App\Models\Setting;
use App\Models\SuggestedChannel;
use App\Models\TargetCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = env('ADMIN_PASSWORD') ?: (app()->isLocal() ? 'ChangeMe!123' : null);
        if ($adminPassword) {
            Admin::updateOrCreate(['email' => env('ADMIN_EMAIL', 'admin@example.com')], [
                'name' => env('ADMIN_NAME', 'Platform Owner'),
                'password' => $adminPassword,
                'role' => 'super_admin',
                'permissions' => ['*'],
                'is_active' => true,
            ]);
        }

        PricingRule::firstOrCreate(['is_active' => true], [
            'service_markup_bps' => (int) config('ads-platform.service_markup_bps', 1500),
            'gateway_fee_bps' => 0,
            'minimum_order_irr' => (int) config('ads-platform.minimum_order_irr', 1_000_000),
            'effective_from' => now(),
        ]);
        Setting::updateOrCreate(['key' => 'usd_to_irr'], ['value' => ['value' => config('ads-platform.usd_to_irr'), 'quoted_at' => now()->toIso8601String()], 'is_public' => true]);
        Setting::updateOrCreate(['key' => 'gram_to_usd'], ['value' => ['value' => config('ads-platform.gram_to_usd'), 'quoted_at' => now()->toIso8601String()], 'is_public' => true]);

        $this->seedPolicies();
        $this->seedCatalog();

        if (app()->isLocal()) {
            $this->seedDemoWorkspace();
        }
    }

    private function seedPolicies(): void
    {
        DB::table('policy_versions')->updateOrInsert(
            ['type' => 'service_terms', 'version' => '1.0.0'],
            [
                'title_fa' => 'شرایط استفاده و پرداخت',
                'title_en' => 'Service and payment terms',
                'content_fa' => 'این سرویس مستقل و غیر وابسته به تلگرام است. تأیید پشتیبانی به معنی تأیید تلگرام نیست و زمان بررسی یا نتیجه کمپین تضمین نمی‌شود. مبلغ نهایی شامل بودجه رسانه، کارمزد خدمات و هزینه‌های اعلام‌شده پیش از پرداخت است. در صورت رد تبلیغ توسط تلگرام، بازپرداخت نقدی انجام نمی‌شود؛ فقط مبلغ مصرف‌نشده و قطعی‌کسرنشده پس از تطبیق مالی به اعتبار تبلیغاتی داخلی تبدیل می‌شود. اعتبار منتقل‌شده به Telegram Ads یا اعتبار تبلیغاتی داخلی قابل برداشت نقدی نیست. کاربر مسئول قانونی بودن محتوا، مقصد، مجوزها و حقوق اشخاص ثالث است.',
                'content_en' => 'This is an independent managed service and is not affiliated with Telegram. Support approval does not guarantee Telegram approval, review time, delivery or results. The final price shows media budget, service fee and disclosed charges before payment. Telegram rejection is not eligible for a cash refund; only funds confirmed as unspent and not finally deducted become restricted internal ad credit after reconciliation. Funds transferred to Telegram Ads and internal ad credit are not cash-withdrawable. The advertiser is responsible for legality, licences and third-party rights.',
                'is_active' => true,
                'effective_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('policy_versions')->updateOrInsert(
            ['type' => 'privacy_kyc', 'version' => '1.0.0'],
            [
                'title_fa' => 'حریم خصوصی مدارک احراز هویت',
                'title_en' => 'KYC privacy notice',
                'content_fa' => 'مدارک هویتی برای احراز هویت پرداخت ریالی، پیشگیری از سوءاستفاده و رسیدگی به تخلف پردازش می‌شوند. مدارک رمزنگاری شده و فقط برای مدیران مجاز قابل دسترس‌اند. مدت نگهداری و فرایند حذف باید پیش از انتشار توسط مالک و مشاور حقوقی نهایی شود.',
                'content_en' => 'Identity documents are processed for rial-payment verification, fraud prevention and investigations. Documents are encrypted and restricted to authorised reviewers. Retention and deletion periods must be approved by the owner and legal counsel before launch.',
                'is_active' => true,
                'effective_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('policy_versions')->updateOrInsert(
            ['type' => 'ads_policy', 'version' => '1.0.0'],
            [
                'title_fa' => 'قوانین محتوای تبلیغات',
                'title_en' => 'Advertising content policy',
                'content_fa' => 'متن حداکثر 160 نویسه است و نباید شکست خط، فهرست، لینک اضافی، لینک کوتاه‌شده یا نمادگذاری فریبنده داشته باشد. متن، مقصد و زبان باید مرتبط باشند. محتوای جنسی یا تکان‌دهنده، نفرت و خشونت، نقض حقوق دیگران، فریب، تبلیغات سیاسی یا مذهبی، قمار، خدمات مالی مضر، ادعاهای پزشکی تأییدنشده، مواد و دخانیات، سلاح، بدافزار، دستکاری رشد و محصولات غیرقانونی پذیرفته نمی‌شوند. کاربر مسئول مجوزها و انطباق قانونی است.',
                'content_en' => 'Ad text is limited to 160 characters and may not contain line breaks, lists, extra links, URL shorteners or manipulative styling. Copy, destination and language must be relevant. Sexual or shocking content, hate or violence, rights infringement, deception, political or religious promotion, gambling, harmful finance, unapproved medical claims, drugs or tobacco, weapons, malware, growth manipulation and unlawful products are prohibited. The advertiser remains responsible for licences and legal compliance.',
                'is_active' => true,
                'effective_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('policy_versions')->updateOrInsert(
            ['type' => 'telegram_rejection_policy', 'version' => '1.0.0'],
            [
                'title_fa' => 'سیاست مالی رد تبلیغ توسط تلگرام',
                'title_en' => 'Telegram rejection financial policy',
                'content_fa' => 'رد تبلیغ توسط تلگرام بازپرداخت نقدی ندارد. پس از تطبیق اپراتور، فقط مبلغ مصرف‌نشده و قطعی‌کسرنشده، در صورت وجود، به اعتبار تبلیغاتی داخلی غیرقابل برداشت تبدیل می‌شود.',
                'content_en' => 'Telegram rejection is not eligible for a cash refund. After operator reconciliation, only funds confirmed as unspent and not finally deducted, if any, become non-withdrawable internal advertising credit.',
                'is_active' => true,
                'effective_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function seedCatalog(): void
    {
        $categories = [
            ['slug' => 'technology', 'title_fa' => 'فناوری و دیجیتال', 'title_en' => 'Technology', 'icon' => 'devices'],
            ['slug' => 'business', 'title_fa' => 'کسب‌وکار', 'title_en' => 'Business', 'icon' => 'business_center'],
            ['slug' => 'education', 'title_fa' => 'آموزش', 'title_en' => 'Education', 'icon' => 'school'],
            ['slug' => 'entertainment', 'title_fa' => 'سرگرمی', 'title_en' => 'Entertainment', 'icon' => 'movie'],
        ];

        foreach ($categories as $position => $attributes) {
            TargetCategory::updateOrCreate(['slug' => $attributes['slug']], [...$attributes, 'is_active' => true, 'sort_order' => $position]);
        }

        if (app()->isLocal()) {
            foreach (TargetCategory::all() as $category) {
                for ($i = 1; $i <= 3; $i++) {
                    $username = 'demo_'.$category->slug.'_'.$i;
                    $channel = SuggestedChannel::updateOrCreate(['username' => $username], [
                        'title' => $category->title_fa.' '.$i,
                        'public_url' => 'https://t.me/'.$username,
                        'language' => 'fa',
                        'members_count' => 1500 * $i,
                        'eligibility_status' => 'unverified',
                        'is_featured' => $i === 1,
                        'is_active' => true,
                        'last_verified_at' => now()->subDays($i),
                        'internal_note' => 'داده نمایشی؛ پیش از انتشار با کانال واقعی جایگزین شود.',
                    ]);
                    $category->channels()->syncWithoutDetaching([$channel->getKey() => ['position' => $i]]);
                }
            }
        }
    }

    private function seedDemoWorkspace(): void
    {
        $user = User::updateOrCreate(['telegram_user_id' => config('ads-platform.demo_telegram_user_id')], [
            'telegram_username' => 'demo_user',
            'first_name' => 'آرمان',
            'last_name' => 'احمدی',
            'display_name' => 'آرمان احمدی',
            'locale' => 'fa',
            'phone' => '+989121234567',
            'phone_verified_at' => now()->subDays(20),
            'kyc_level' => KycLevel::RialVerified,
            'account_status' => 'active',
            'last_seen_at' => now(),
        ]);

        $nationalId = '0013542648';
        $kyc = KycApplication::updateOrCreate(['user_id' => $user->id, 'version' => 1], [
            'status' => KycStatus::Approved,
            'legal_name_encrypted' => 'آرمان احمدی',
            'legal_name_search' => 'آرمان احمدی',
            'national_id_encrypted' => $nationalId,
            'national_id_hmac' => hash_hmac('sha256', $nationalId, config('app.key')),
            'submitted_at' => now()->subDays(20),
            'reviewed_at' => now()->subDays(19),
        ]);

        $pan = '6037997512345670';
        FundingCard::updateOrCreate(['pan_hmac' => hash_hmac('sha256', $pan, config('app.key'))], [
            'user_id' => $user->id,
            'kyc_application_id' => $kyc->id,
            'pan_encrypted' => $pan,
            'bin' => substr($pan, 0, 6),
            'last4' => substr($pan, -4),
            'holder_name_encrypted' => 'آرمان احمدی',
            'holder_name_search' => 'آرمان احمدی',
            'status' => 'approved',
            'verification_method' => 'admin_review',
            'verified_at' => now()->subDays(19),
        ]);

        $wallet = LedgerAccount::firstOrCreate([
            'owner_type' => $user->getMorphClass(), 'owner_id' => $user->id, 'currency' => 'IRR', 'type' => 'wallet_available',
        ], ['normal_balance' => 'credit', 'name' => 'موجودی قابل استفاده']);
        $clearing = LedgerAccount::firstOrCreate([
            'owner_type' => 'system', 'owner_id' => 0, 'currency' => 'IRR', 'type' => 'gateway_clearing',
        ], ['normal_balance' => 'debit', 'name' => 'تسویه درگاه']);

        if (! LedgerTransaction::where('idempotency_key', 'seed:wallet:demo')->exists()) {
            $transaction = LedgerTransaction::create([
                'public_id' => (string) Str::uuid(), 'type' => 'wallet_top_up',
                'idempotency_key' => 'seed:wallet:demo', 'description' => 'موجودی نمایشی',
            ]);
            LedgerEntry::insert([
                ['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $wallet->id, 'direction' => 'credit', 'amount_minor' => 57_500_000, 'currency' => 'IRR', 'created_at' => now(), 'updated_at' => now()],
                ['ledger_transaction_id' => $transaction->id, 'ledger_account_id' => $clearing->id, 'direction' => 'debit', 'amount_minor' => 57_500_000, 'currency' => 'IRR', 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $order = Order::firstOrCreate(['public_id' => '01JDEMOADSPLATFORM00000001'], [
            'user_id' => $user->id,
            'status' => OrderStatus::Active,
            'payment_status' => OrderPaymentStatus::Paid,
            'funding_mode' => 'wallet',
            'media_budget_irr' => 100_000_000,
            'service_markup_bps' => 1500,
            'service_fee_irr' => 15_000_000,
            'total_irr' => 115_000_000,
            'gram_amount' => 51.234000000,
            'usd_amount' => 166.75,
            'planned_start_at' => now()->subDays(3),
            'funded_at' => now()->subDays(7),
        ]);
        if (! $order->currentRevision) {
            $revision = $order->revisions()->create([
                'revision_no' => 1,
                'internal_title' => 'معرفی نسخه جدید محصول',
                'ad_text' => 'ابزار ساده مدیریت سفارش و گزارش تبلیغات تلگرام را ببینید.',
                'destination_type' => 'bot',
                'destination_url' => 'https://t.me/example_bot',
                'placement_type' => 'channel_posts',
                'targeting_payload' => ['mode' => 'channel_posts'],
                'impression_goal' => 120000,
                'frequency_cap' => 2,
                'plan' => 'competitive',
                'cpm_gram' => 0.35,
                'language' => 'fa',
                'is_locked' => true,
            ]);
            $order->update(['current_revision_id' => $revision->id]);
        }

        if (! $order->metrics()->exists()) {
            foreach ([5 => [18000, 7.5], 4 => [41000, 15.2], 3 => [68000, 25.7], 2 => [91000, 35.1], 1 => [108500, 43.8]] as $days => [$impressions, $spend]) {
                CampaignMetricSnapshot::create([
                    'order_id' => $order->id,
                    'as_of_at' => now()->subDays($days),
                    'impressions' => $impressions,
                    'joins' => (int) ($impressions * 0.006),
                    'bot_starts' => (int) ($impressions * 0.003),
                    'spend_gram' => $spend,
                    'remaining_budget_gram' => max(0, 51.234 - $spend),
                    'source' => 'manual',
                ]);
            }
        }
    }
}
