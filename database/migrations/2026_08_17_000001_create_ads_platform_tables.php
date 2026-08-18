<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 40)->default('operator');
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('private_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('storage_key', 512)->unique();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->index();
            $table->boolean('is_encrypted')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('status', 32)->default('draft')->index();
            $table->text('legal_name_encrypted');
            $table->string('legal_name_search', 128)->nullable()->index();
            $table->text('national_id_encrypted')->nullable();
            $table->string('national_id_hmac', 64)->nullable()->index();
            $table->text('user_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'version']);
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('private_file_id')->constrained()->restrictOnDelete();
            $table->string('kind', 40);
            $table->timestamps();
            $table->unique(['kyc_application_id', 'kind']);
        });

        Schema::create('funding_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kyc_application_id')->nullable()->constrained()->nullOnDelete();
            $table->text('pan_encrypted');
            $table->string('pan_hmac', 64)->unique();
            $table->string('bin', 8)->index();
            $table->string('last4', 4)->index();
            $table->text('holder_name_encrypted');
            $table->string('holder_name_search', 128)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('verification_method', 40)->default('admin_review');
            $table->json('verification_result')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained()->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason_code', 50)->nullable();
            $table->text('note')->nullable();
            $table->json('checklist')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('service_markup_bps')->default(1500);
            $table->unsignedInteger('gateway_fee_bps')->default(0);
            $table->unsignedBigInteger('minimum_order_irr')->default(1000000);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('target_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title_fa');
            $table->string('title_en');
            $table->text('description_fa')->nullable();
            $table->text('description_en')->nullable();
            $table->string('icon', 40)->default('folder');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('suggested_channels', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id')->nullable()->unique();
            $table->string('username')->unique();
            $table->string('title');
            $table->string('public_url', 2048);
            $table->string('avatar_url', 2048)->nullable();
            $table->string('language', 8)->default('fa');
            $table->unsignedBigInteger('members_count')->default(0);
            $table->string('eligibility_status', 32)->default('unverified')->index();
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_verified_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();
        });

        Schema::create('target_category_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggested_channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['target_category_id', 'suggested_channel_id'], 'category_channel_unique');
            $table->unique(['target_category_id', 'position'], 'category_position_unique');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 40)->default('draft')->index();
            $table->string('payment_status', 32)->default('unfunded')->index();
            $table->string('funding_mode', 32)->nullable();
            $table->unsignedBigInteger('media_budget_irr')->default(0);
            $table->unsignedInteger('service_markup_bps')->default(1500);
            $table->unsignedBigInteger('service_fee_irr')->default(0);
            $table->unsignedBigInteger('gateway_fee_irr')->default(0);
            $table->unsignedBigInteger('total_irr')->default(0);
            $table->decimal('gram_amount', 24, 9)->nullable();
            $table->decimal('usd_amount', 18, 2)->nullable();
            $table->decimal('usd_to_irr_rate', 18, 4)->nullable();
            $table->decimal('gram_to_usd_rate', 18, 8)->nullable();
            $table->unsignedInteger('conversion_margin_bps')->default(0);
            $table->string('rate_source', 80)->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('quote_expires_at')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('revision_no')->default(1);
            $table->string('internal_title');
            $table->text('ad_text');
            $table->string('destination_type', 30);
            $table->string('destination_url', 2048);
            $table->string('placement_type', 40)->default('channel_posts');
            $table->json('targeting_payload')->nullable();
            $table->unsignedBigInteger('impression_goal')->nullable();
            $table->unsignedTinyInteger('frequency_cap')->nullable();
            $table->string('plan', 30)->default('standard');
            $table->decimal('cpm_gram', 18, 9)->nullable();
            $table->string('language', 8)->default('fa');
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->unique(['order_id', 'revision_no']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('current_revision_id')->nullable()->after('user_id')->constrained('campaign_revisions')->nullOnDelete();
        });

        Schema::create('campaign_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_revision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggested_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 20)->default('manual');
            $table->string('channel_username');
            $table->string('channel_title')->nullable();
            $table->string('public_url', 2048);
            $table->unsignedBigInteger('members_snapshot')->nullable();
            $table->string('validation_status', 32)->default('pending');
            $table->timestamps();
        });

        Schema::create('telegram_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_revision_id')->constrained()->cascadeOnDelete();
            $table->string('external_ad_id')->nullable()->index();
            $table->string('external_account_label')->nullable();
            $table->string('status', 32)->default('pending_operator')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('proof_file_id')->nullable()->constrained('private_files')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->nullableMorphs('actor');
            $table->string('reason_code', 80)->nullable();
            $table->text('note')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('operator_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 40)->index();
            $table->string('status', 30)->default('open')->index();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamp('as_of_at')->index();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('joins')->default(0);
            $table->unsignedBigInteger('bot_starts')->default(0);
            $table->decimal('spend_gram', 24, 9)->default(0);
            $table->decimal('remaining_budget_gram', 24, 9)->nullable();
            $table->string('source', 20)->default('manual');
            $table->foreignId('proof_file_id')->nullable()->constrained('private_files')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('campaign_metric_snapshots')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('currency', 12)->default('IRR');
            $table->string('type', 40)->index();
            $table->string('normal_balance', 8);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['owner_type', 'owner_id', 'currency', 'type'], 'ledger_owner_currency_type_unique');
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 40)->index();
            $table->nullableMorphs('reference');
            $table->string('idempotency_key', 160)->unique();
            $table->text('description');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->string('direction', 8);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 12)->default('IRR');
            $table->timestamps();
            $table->index(['ledger_account_id', 'created_at']);
        });

        Schema::create('fund_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_irr');
            $table->string('status', 20)->default('active');
            $table->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('provider', 30)->index();
            $table->string('merchant_reference', 100)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 12)->default('IRR');
            $table->string('status', 32)->default('created')->index();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_intent_id')->constrained()->cascadeOnDelete();
            $table->string('provider_reference')->nullable()->index();
            $table->string('authority')->nullable()->unique();
            $table->text('redirect_url')->nullable();
            $table->string('verify_code', 30)->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_key', 160)->unique();
            $table->boolean('signature_valid')->nullable();
            $table->json('payload_redacted');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32)->default('refund_to_origin');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 12)->default('IRR');
            $table->string('status', 32)->default('requested')->index();
            $table->string('destination_masked')->nullable();
            $table->text('reason');
            $table->text('admin_note')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('ledger_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('status', 30)->default('open')->index();
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('sender');
            $table->text('body');
            $table->foreignId('private_file_id')->nullable()->constrained('private_files')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('version', 30);
            $table->string('title_fa');
            $table->string('title_en');
            $table->longText('content_fa');
            $table->longText('content_en');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->unique(['type', 'version']);
        });

        Schema::create('policy_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['user_id', 'policy_version_id', 'order_id'], 'policy_acceptance_unique');
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('message');
            $table->json('audience_filters')->nullable();
            $table->string('status', 30)->default('draft')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['broadcast_id', 'user_id']);
        });

        Schema::create('telegram_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->boolean('is_public')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('actor');
            $table->string('action', 100)->index();
            $table->nullableMorphs('subject');
            $table->json('before_redacted')->nullable();
            $table->json('after_redacted')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_revision_id');
        });

        foreach ([
            'audit_logs', 'settings', 'telegram_webhook_events', 'broadcast_recipients', 'broadcasts', 'policy_acceptances',
            'policy_versions', 'ticket_messages', 'support_tickets', 'payout_requests', 'payment_webhook_events',
            'payment_attempts', 'payment_intents', 'fund_holds', 'ledger_entries', 'ledger_transactions',
            'ledger_accounts', 'campaign_metric_snapshots', 'operator_tasks', 'order_status_events',
            'telegram_submissions', 'campaign_targets', 'campaign_revisions', 'orders',
            'target_category_channels', 'suggested_channels', 'target_categories', 'pricing_rules',
            'kyc_reviews', 'funding_cards', 'kyc_documents', 'kyc_applications', 'private_files', 'admins',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
