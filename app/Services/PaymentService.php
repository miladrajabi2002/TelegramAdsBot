<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Admin;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Payments\Contracts\ZarinPayGateway;
use App\Services\Payments\Data\ZarinPayVerifyResult;
use App\Services\Payments\Exceptions\PaymentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class PaymentService
{
    private const MINIMUM_ZARINPAY_AMOUNT_IRR = 1001;

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CampaignTransitionService $campaignTransitions,
        private readonly ZarinPayGateway $zarinPay,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function createZarinPayIntent(
        User $user,
        PaymentPurpose $purpose,
        int $amountIrr,
        string $callbackUrl,
        string $merchantReference,
        ?Order $order = null,
        ?string $description = null,
        ?int $storeId = null,
    ): PaymentIntent {
        $merchantReference = trim($merchantReference);
        $this->assertMerchantReference($merchantReference);
        $this->assertCallbackUrl($callbackUrl);

        if ($amountIrr < self::MINIMUM_ZARINPAY_AMOUNT_IRR) {
            throw new PaymentException('ZarinPay amount must be greater than 1000 IRR.');
        }

        $freshUser = User::query()->findOrFail($user->getKey());

        if ($freshUser->account_status !== 'active' || ! $freshUser->canUseRialPayments()) {
            throw new PaymentException('Approved KYC and an approved funding card are required for rial payments.');
        }

        $freshOrder = $this->validatePaymentTarget(
            $freshUser,
            $purpose,
            $amountIrr,
            $order,
            $merchantReference,
        );

        $intent = PaymentIntent::query()->firstOrCreate([
            'merchant_reference' => $merchantReference,
        ], [
            'user_id' => $freshUser->getKey(),
            'order_id' => $freshOrder?->getKey(),
            'purpose' => $purpose,
            'provider' => 'zarinpay',
            'amount_minor' => $amountIrr,
            'currency' => 'IRR',
            'status' => PaymentStatus::Created,
            'metadata' => array_filter([
                'description' => $description,
                'store_id' => $storeId,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);

        $this->assertIntentMatches(
            $intent,
            $freshUser,
            $purpose,
            $amountIrr,
            'IRR',
            $freshOrder,
            'zarinpay',
        );

        $existingAttempt = $intent->attempts()->latest('id')->first();

        if ($existingAttempt?->redirect_url !== null
            || $intent->status !== PaymentStatus::Created) {
            return $intent->load('attempts');
        }

        try {
            $result = $this->zarinPay->createPayment(
                amountIrr: $amountIrr,
                merchantReference: $merchantReference,
                callbackUrl: $callbackUrl,
                customerUserId: $freshUser->getKey(),
                description: $description,
                storeId: $storeId,
            );
        } catch (Throwable $exception) {
            $this->markCreationUncertain($intent, $exception);

            throw new PaymentException('ZarinPay payment creation could not be confirmed.', 0, $exception);
        }

        if (! $result->successful
            || trim((string) $result->authority) === ''
            || trim((string) $result->paymentLink) === ''
            || ! $this->isTrustedZarinPayUrl((string) $result->paymentLink)) {
            DB::transaction(function () use ($intent, $result): void {
                $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
                $locked->forceFill(['status' => PaymentStatus::Failed])->save();
                PaymentAttempt::create([
                    'payment_intent_id' => $locked->getKey(),
                    'authority' => $result->authority,
                    'redirect_url' => $this->isTrustedZarinPayUrl((string) $result->paymentLink)
                        ? $result->paymentLink
                        : null,
                    'provider_response' => $this->redactProviderPayload($result->raw),
                ]);
            });

            throw new PaymentException($result->message ?: 'ZarinPay rejected payment creation.');
        }

        $intent = DB::transaction(function () use ($intent, $result, $freshOrder): PaymentIntent {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
            $attempt = $locked->attempts()->latest('id')->lockForUpdate()->first();

            if ($attempt === null) {
                PaymentAttempt::create([
                    'payment_intent_id' => $locked->getKey(),
                    'authority' => $result->authority,
                    'redirect_url' => $result->paymentLink,
                    'provider_response' => $this->redactProviderPayload($result->raw),
                ]);
            } elseif ($attempt->authority !== $result->authority) {
                $locked->forceFill(['status' => PaymentStatus::ManualReview])->save();
                throw new PaymentException('ZarinPay returned conflicting authorities for one payment intent.');
            }

            $locked->forceFill([
                'status' => PaymentStatus::Pending,
                'expires_at' => now()->addMinutes(30),
            ])->save();

            if ($freshOrder !== null) {
                Order::query()
                    ->whereKey($freshOrder->getKey())
                    ->where('payment_status', OrderPaymentStatus::Unfunded->value)
                    ->update([
                        'payment_status' => OrderPaymentStatus::Pending->value,
                        'funding_mode' => 'zarinpay_direct',
                        'updated_at' => now(),
                    ]);
            }

            return $locked->refresh()->load('attempts');
        }, 3);

        $this->auditLogger->log(
            'payment.zarinpay_created',
            $freshUser,
            $intent,
            [],
            [
                'status' => PaymentStatus::Pending->value,
                'amount_minor' => $amountIrr,
                'currency' => 'IRR',
            ],
        );

        return $intent;
    }

    /**
     * Process the unauthenticated browser callback by performing a mandatory
     * server-to-server verification. Both 100 and 101 prove payment, but the
     * local ledger is posted at most once.
     */
    public function verifyZarinPay(string $merchantReference, string $authority): PaymentIntent
    {
        $merchantReference = trim($merchantReference);
        $authority = trim($authority);

        if ($merchantReference === '' || $authority === '') {
            throw new PaymentException('ZarinPay callback is missing its order reference or authority.');
        }

        $intent = PaymentIntent::query()
            ->where('provider', 'zarinpay')
            ->where('merchant_reference', $merchantReference)
            ->firstOrFail();
        $attempt = $intent->attempts()->where('authority', $authority)->first();

        if ($attempt === null) {
            throw new PaymentException('ZarinPay callback authority does not belong to this payment intent.');
        }

        if ($intent->status === PaymentStatus::Succeeded) {
            return $intent->load('attempts');
        }

        DB::transaction(function () use ($intent): void {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());

            if (! in_array($locked->status, [
                PaymentStatus::Succeeded,
                PaymentStatus::PartiallyRefunded,
                PaymentStatus::Refunded,
                PaymentStatus::Chargeback,
            ], true)) {
                $locked->forceFill(['status' => PaymentStatus::Verifying])->save();
            }
        });

        try {
            $result = $this->zarinPay->verifyPayment($authority);
        } catch (Throwable $exception) {
            $this->markVerificationUncertain($intent, $attempt, $exception);

            throw new PaymentException('ZarinPay verification could not be completed.', 0, $exception);
        }

        if ($result->provesPayment()) {
            $mismatch = $this->zarinPayVerificationMismatch($intent, $authority, $result);

            if ($mismatch !== null) {
                // Log the full ZarinPay response so the operator can see
                // exactly what ZarinPay returned vs what we expected. This
                // is critical because mismatches often come from gateway
                // quirks (Rial vs Toman, string vs int order_id, etc.)
                // and without this log there's no way to debug them.
                Log::warning('ZarinPay verification mismatch', [
                    'intent_id' => $intent->id,
                    'merchant_reference' => $intent->merchant_reference,
                    'expected_amount_minor' => (int) $intent->amount_minor,
                    'received_amount_irr' => $result->amountIrr,
                    'expected_authority' => $authority,
                    'received_authority' => $result->authority,
                    'received_merchant_reference' => $result->merchantReference,
                    'verify_code' => $result->code,
                    'raw_response' => $result->raw,
                    'mismatch_reason' => $mismatch,
                ]);

                return $this->markVerificationMismatch($intent, $attempt, $result, $mismatch);
            }

            return $this->settleSuccessfulIntent($intent, $authority, [
                'verify_code' => $result->code,
                'payment_id' => $result->paymentId,
                // Always credit the intent's stored amount — we created
                // the intent with this amount and ZarinPay confirmed the
                // payment was successful. The ZarinPay response's amount
                // field might be in Toman (10x smaller) or missing
                // entirely, but that doesn't change how much we credit
                // to the user's wallet.
                'amount_minor' => (int) $intent->amount_minor,
                'currency' => 'IRR',
                'merchant_reference' => $intent->merchant_reference,
                'authority' => $authority,
                'gateway_response' => $result->raw,
            ]);
        }

        // Verification returned a non-success code — log it so the
        // operator can see what ZarinPay actually said.
        Log::warning('ZarinPay verification returned non-success', [
            'intent_id' => $intent->id,
            'merchant_reference' => $intent->merchant_reference,
            'verify_code' => $result->code,
            'message' => $result->message,
            'raw_response' => $result->raw,
        ]);

        return $this->recordUnsuccessfulVerification($intent, $attempt, $result);
    }

    /**
     * Provider-neutral settlement entry point. The caller must authenticate the
     * provider notification and verify its terminal success status first.
     *
     * @param  array<string, mixed>  $payload  A redacted/normalised provider payload.
     */
    public function settleSuccessfulIntent(
        PaymentIntent $intent,
        string $providerReference,
        array $payload = [],
    ): PaymentIntent {
        $providerReference = trim($providerReference);

        if (! $intent->exists || $providerReference === '') {
            throw new PaymentException('A persisted intent and provider reference are required for settlement.');
        }

        return DB::transaction(function () use ($intent, $providerReference, $payload): PaymentIntent {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
            $this->assertSettlementPayloadMatches($locked, $payload);

            $attempt = $locked->attempts()
                ->where(function ($query) use ($providerReference): void {
                    $query->where('provider_reference', $providerReference)
                        ->orWhere('authority', $providerReference);
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($locked->status === PaymentStatus::Succeeded) {
                if ($attempt === null) {
                    throw new PaymentException('Settled intent was called with a conflicting provider reference.');
                }

                return $locked->load('attempts');
            }

            if (in_array($locked->status, [
                PaymentStatus::PartiallyRefunded,
                PaymentStatus::Refunded,
                PaymentStatus::Chargeback,
                PaymentStatus::Cancelled,
            ], true)) {
                throw new PaymentException("Payment intent in [{$locked->status->value}] cannot be settled.");
            }

            $attempt ??= $this->createSettlementAttempt($locked, $providerReference);

            $systemClearing = $this->ledger->systemAccount(
                $locked->currency,
                Str::limit('gateway_'.$this->safeProviderName($locked->provider).'_clearing', 40, ''),
                'debit',
                strtoupper($locked->provider).' clearing',
            );
            $walletType = $locked->purpose === PaymentPurpose::OrderPayment
                ? 'wallet_reserved'
                : 'wallet_available';
            $customerWallet = $this->ledger->accountFor(
                $locked->user,
                $locked->currency,
                $walletType,
                'credit',
                "Customer {$walletType}",
            );

            $journal = $this->ledger->post(
                type: 'payment_settlement',
                idempotencyKey: 'payment:settle:'.$locked->public_id,
                description: "Settle {$locked->provider} payment {$locked->merchant_reference}",
                entries: [
                    [
                        'account' => $systemClearing,
                        'direction' => 'debit',
                        'amount_minor' => (int) $locked->amount_minor,
                        'currency' => $locked->currency,
                    ],
                    [
                        'account' => $customerWallet,
                        'direction' => 'credit',
                        'amount_minor' => (int) $locked->amount_minor,
                        'currency' => $locked->currency,
                    ],
                ],
                reference: $locked,
            );

            $attempt->forceFill([
                'provider_reference' => (string) ($payload['payment_id'] ?? $attempt->provider_reference ?? $providerReference),
                'verify_code' => isset($payload['verify_code']) ? (string) $payload['verify_code'] : $attempt->verify_code,
                'provider_response' => $this->redactProviderPayload($payload),
                'verified_at' => now(),
            ])->save();

            $locked->forceFill([
                'status' => PaymentStatus::Succeeded,
                'verified_at' => now(),
            ])->save();

            if ($locked->purpose === PaymentPurpose::OrderPayment) {
                $this->fundOrderFromSettledIntent($locked, $journal);
            }

            $this->auditLogger->log(
                'payment.settled',
                $locked,
                $locked,
                ['status' => $intent->status instanceof PaymentStatus ? $intent->status->value : (string) $intent->status],
                [
                    'status' => PaymentStatus::Succeeded->value,
                    'provider_reference' => $providerReference,
                    'ledger_transaction_id' => $journal->getKey(),
                ],
            );

            return $locked->refresh()->load('attempts');
        }, 3);
    }

    public function fundOrderFromWallet(User $user, Order $order, string $idempotencyKey): Order
    {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new PaymentException('Wallet funding idempotency key is required.');
        }

        if (! $user->exists || ! $order->exists || (int) $order->user_id !== (int) $user->getKey()) {
            throw new PaymentException('The order does not belong to this customer.');
        }

        if ($user->account_status !== 'active') {
            throw new PaymentException('The customer account is not active.');
        }

        return DB::transaction(function () use ($user, $order, $idempotencyKey): Order {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->getKey());

            $existingJournal = LedgerTransaction::query()
                ->where('idempotency_key', 'wallet:order:'.$idempotencyKey)
                ->first();

            if ($existingJournal !== null) {
                if ($lockedOrder->payment_status !== OrderPaymentStatus::Paid) {
                    throw new PaymentException('Wallet journal exists but the order is not marked paid.');
                }

                return $lockedOrder;
            }

            if ($lockedOrder->status !== OrderStatus::AwaitingPayment
                || $lockedOrder->payment_status !== OrderPaymentStatus::Unfunded) {
                throw new PaymentException('Only an unfunded order awaiting payment can use wallet funding.');
            }

            if ((int) $lockedOrder->total_irr <= 0) {
                throw new PaymentException('Order total must be positive.');
            }

            $available = $this->ledger->accountFor($user, 'IRR', 'wallet_available', 'credit', 'Customer available wallet');
            $restricted = $this->ledger->accountFor(
                $user,
                'IRR',
                'ad_credit_restricted',
                'credit',
                'Restricted advertising credit',
            );
            $reserved = $this->ledger->accountFor($user, 'IRR', 'wallet_reserved', 'credit', 'Customer reserved wallet');

            LedgerAccount::query()
                ->whereKey([$available->getKey(), $restricted->getKey(), $reserved->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $total = (int) $lockedOrder->total_irr;
            $restrictedToUse = min(max(0, $this->ledger->balance($restricted)), $total);
            $availableToUse = $total - $restrictedToUse;

            if ($this->ledger->balance($available) < $availableToUse) {
                throw new PaymentException('Combined advertising credit and wallet balance are insufficient.');
            }

            $entries = [];

            if ($restrictedToUse > 0) {
                $entries[] = [
                    'account' => $restricted,
                    'direction' => 'debit',
                    'amount_minor' => $restrictedToUse,
                ];
            }

            if ($availableToUse > 0) {
                $entries[] = [
                    'account' => $available,
                    'direction' => 'debit',
                    'amount_minor' => $availableToUse,
                ];
            }

            $entries[] = [
                'account' => $reserved,
                'direction' => 'credit',
                'amount_minor' => $total,
            ];

            $journal = $this->ledger->post(
                type: 'order_wallet_reservation',
                idempotencyKey: 'wallet:order:'.$idempotencyKey,
                description: "Reserve advertising credit and wallet funds for order {$lockedOrder->public_id}",
                entries: $entries,
                reference: $lockedOrder,
            );

            $this->createFundHold($lockedOrder, (int) $lockedOrder->total_irr, $journal);
            $lockedOrder->forceFill([
                'payment_status' => OrderPaymentStatus::Paid,
                'funding_mode' => 'wallet',
                'funded_at' => now(),
            ])->save();

            return $this->campaignTransitions->transition(
                $lockedOrder,
                OrderStatus::SupportReview,
                $journal,
                'wallet_funded',
            );
        }, 3);
    }

    public function walletBalance(User $user, string $currency = 'IRR'): int
    {
        $account = LedgerAccount::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->where('currency', strtoupper($currency))
            ->where('type', 'wallet_available')
            ->first();

        return $account === null ? 0 : $this->ledger->balance($account);
    }

    public function restrictedAdCreditBalance(User $user, string $currency = 'IRR'): int
    {
        $account = LedgerAccount::query()
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->where('currency', strtoupper($currency))
            ->where('type', 'ad_credit_restricted')
            ->first();

        return $account === null ? 0 : $this->ledger->balance($account);
    }

    public function reconcileTelegramRejection(
        Order $order,
        Admin $admin,
        int $telegramSpentIrr,
        ?string $note = null,
    ): Order {
        return $this->reconcileFinalCampaignHold(
            $order,
            $admin,
            $telegramSpentIrr,
            $note,
            OrderStatus::TelegramRejected,
            'reconcile_telegram_rejection',
            'telegram_rejection_reconciliation',
            'telegram-rejection:',
            'order.telegram_rejection_reconciled',
        );
    }

    public function reconcileCompletedCampaign(
        Order $order,
        Admin $admin,
        int $telegramSpentIrr,
        ?string $note = null,
    ): Order {
        return $this->reconcileFinalCampaignHold(
            $order,
            $admin,
            $telegramSpentIrr,
            $note,
            OrderStatus::Completed,
            'reconcile_completed_campaign',
            'completed_campaign_reconciliation',
            'completed-campaign:',
            'order.completed_campaign_reconciled',
        );
    }

    private function reconcileFinalCampaignHold(
        Order $order,
        Admin $admin,
        int $telegramSpentIrr,
        ?string $note,
        OrderStatus $expectedStatus,
        string $taskType,
        string $journalType,
        string $idempotencyPrefix,
        string $auditAction,
    ): Order {
        if (! $admin->exists || ! $admin->is_active || ! $admin->hasPermission('orders.manage')) {
            throw new PaymentException('An authorized admin is required for campaign reconciliation.');
        }

        return DB::transaction(function () use (
            $order,
            $admin,
            $telegramSpentIrr,
            $note,
            $expectedStatus,
            $taskType,
            $journalType,
            $idempotencyPrefix,
            $auditAction,
        ): Order {
            $lockedOrder = Order::query()->with('user')->lockForUpdate()->findOrFail($order->getKey());
            if ($lockedOrder->status !== $expectedStatus || $lockedOrder->payment_status !== OrderPaymentStatus::Paid) {
                throw new PaymentException("Only a paid [{$expectedStatus->value}] order can be reconciled by this operation.");
            }
            if ($telegramSpentIrr < 0 || $telegramSpentIrr > (int) $lockedOrder->media_budget_irr) {
                throw new PaymentException('Telegram spend must be between zero and the media budget.');
            }

            $task = $lockedOrder->operatorTasks()->where('type', $taskType)->lockForUpdate()->first();
            if ($task?->status === 'completed') {
                if ((int) data_get($task->context, 'telegram_spent_irr', -1) !== $telegramSpentIrr) {
                    throw new PaymentException('The rejection was already reconciled with a different spend amount.');
                }

                return $lockedOrder;
            }

            $hold = DB::table('fund_holds')->where('order_id', $lockedOrder->getKey())
                ->where('status', 'active')->lockForUpdate()->first();
            if (! $hold || (int) $hold->amount_irr !== (int) $lockedOrder->total_irr) {
                throw new PaymentException('The active order fund hold is missing or does not match the order total.');
            }

            $reserved = $this->ledger->accountFor($lockedOrder->user, 'IRR', 'wallet_reserved', 'credit', 'Customer reserved funds');
            if ($this->ledger->balance($reserved) < (int) $lockedOrder->total_irr) {
                throw new PaymentException('Reserved wallet liability is lower than the order hold.');
            }

            $eligibleAdCredit = (int) $lockedOrder->media_budget_irr - $telegramSpentIrr;
            $entries = [[
                'account' => $reserved,
                'direction' => 'debit',
                'amount_minor' => (int) $lockedOrder->total_irr,
            ]];
            if ($eligibleAdCredit > 0) {
                $entries[] = [
                    'account' => $this->ledger->accountFor($lockedOrder->user, 'IRR', 'ad_credit_restricted', 'credit', 'Restricted advertising credit'),
                    'direction' => 'credit',
                    'amount_minor' => $eligibleAdCredit,
                ];
            }
            if ($telegramSpentIrr > 0) {
                $entries[] = [
                    'account' => $this->ledger->systemAccount('IRR', 'telegram_media_settlement', 'credit', 'Telegram media settlement'),
                    'direction' => 'credit',
                    'amount_minor' => $telegramSpentIrr,
                ];
            }
            if ((int) $lockedOrder->service_fee_irr > 0) {
                $entries[] = [
                    'account' => $this->ledger->systemAccount('IRR', 'managed_service_revenue', 'credit', 'Managed service revenue'),
                    'direction' => 'credit',
                    'amount_minor' => (int) $lockedOrder->service_fee_irr,
                ];
            }
            if ((int) $lockedOrder->gateway_fee_irr > 0) {
                $entries[] = [
                    'account' => $this->ledger->systemAccount('IRR', 'gateway_fee_recovery', 'credit', 'Gateway fee recovery'),
                    'direction' => 'credit',
                    'amount_minor' => (int) $lockedOrder->gateway_fee_irr,
                ];
            }

            $journal = $this->ledger->post(
                type: $journalType,
                idempotencyKey: $idempotencyPrefix.$lockedOrder->public_id,
                description: "Reconcile final Telegram spend for order {$lockedOrder->public_id}",
                entries: $entries,
                reference: $lockedOrder,
                createdByAdminId: $admin->getKey(),
            );

            DB::table('fund_holds')->where('id', $hold->id)->update([
                'status' => 'reconciled',
                'released_at' => now(),
                'updated_at' => now(),
            ]);
            $context = [
                ...($task?->context ?? []),
                'telegram_spent_irr' => $telegramSpentIrr,
                'restricted_ad_credit_irr' => $eligibleAdCredit,
                'service_fee_irr' => (int) $lockedOrder->service_fee_irr,
                'gateway_fee_irr' => (int) $lockedOrder->gateway_fee_irr,
                'ledger_transaction_id' => $journal->getKey(),
                'note' => $note,
            ];
            if ($task) {
                $task->update([
                    'status' => 'completed',
                    'assigned_admin_id' => $admin->getKey(),
                    'completed_at' => now(),
                    'context' => $context,
                ]);
            } else {
                $lockedOrder->operatorTasks()->create([
                    'type' => $taskType,
                    'status' => 'completed',
                    'assigned_admin_id' => $admin->getKey(),
                    'completed_at' => now(),
                    'context' => $context,
                ]);
            }

            $this->auditLogger->log(
                $auditAction,
                $admin,
                $lockedOrder,
                before: ['fund_hold_status' => 'active'],
                after: $context,
                reason: $note,
            );

            return $lockedOrder->refresh();
        }, 3);
    }

    private function validatePaymentTarget(
        User $user,
        PaymentPurpose $purpose,
        int $amountIrr,
        ?Order $order,
        string $merchantReference,
    ): ?Order {
        if ($purpose === PaymentPurpose::WalletTopUp) {
            if ($order !== null) {
                throw new PaymentException('Wallet top-ups may not be attached to an order.');
            }

            return null;
        }

        if ($order === null || ! $order->exists) {
            throw new PaymentException('Direct order payments require a persisted order.');
        }

        $freshOrder = Order::query()->findOrFail($order->getKey());

        if ((int) $freshOrder->user_id !== (int) $user->getKey()) {
            throw new PaymentException('The payment order does not belong to this customer.');
        }

        if ($freshOrder->status !== OrderStatus::AwaitingPayment
            || ! in_array($freshOrder->payment_status, [OrderPaymentStatus::Unfunded, OrderPaymentStatus::Pending], true)) {
            throw new PaymentException('The order is not accepting payment.');
        }

        if ((int) $freshOrder->total_irr !== $amountIrr) {
            throw new PaymentException('Payment amount does not match the immutable order total.');
        }

        $otherLiveIntent = $freshOrder->paymentIntents()
            ->where('merchant_reference', '!=', $merchantReference)
            ->whereIn('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Verifying->value,
                PaymentStatus::Succeeded->value,
            ])
            ->exists();

        if ($otherLiveIntent) {
            throw new PaymentException('This order already has an active or successful payment intent.');
        }

        return $freshOrder;
    }

    private function assertIntentMatches(
        PaymentIntent $intent,
        User $user,
        PaymentPurpose $purpose,
        int $amount,
        string $currency,
        ?Order $order,
        string $provider,
    ): void {
        $matches = (int) $intent->user_id === (int) $user->getKey()
            && (string) ($intent->order_id ?? '') === (string) ($order?->getKey() ?? '')
            && $intent->purpose === $purpose
            && (int) $intent->amount_minor === $amount
            && strtoupper($intent->currency) === strtoupper($currency)
            && $intent->provider === $provider;

        if (! $matches) {
            throw new PaymentException('Payment idempotency key was reused with different parameters.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertSettlementPayloadMatches(PaymentIntent $intent, array $payload): void
    {
        if (array_key_exists('amount_minor', $payload)
            && (! is_numeric($payload['amount_minor']) || (int) $payload['amount_minor'] !== (int) $intent->amount_minor)) {
            throw new PaymentException('Verified provider amount does not match the payment intent.');
        }

        if (array_key_exists('currency', $payload)
            && strtoupper((string) $payload['currency']) !== strtoupper($intent->currency)) {
            throw new PaymentException('Verified provider currency does not match the payment intent.');
        }

        if (array_key_exists('merchant_reference', $payload)
            && ! hash_equals($intent->merchant_reference, (string) $payload['merchant_reference'])) {
            throw new PaymentException('Verified provider order reference does not match the payment intent.');
        }
    }

    private function zarinPayVerificationMismatch(
        PaymentIntent $intent,
        string $authority,
        ZarinPayVerifyResult $result,
    ): ?string {
        // ─── AMOUNT CHECK ────────────────────────────────────────────────
        // ZarinPay docs say amounts are in IRR (Rial). The verify-payment
        // response should echo back the same amount we sent. But some
        // gateway integrations have been observed to:
        //   - Omit the amount entirely (return null)
        //   - Return the amount in Toman (10x smaller)
        //   - Return as a string instead of int
        //
        // We accept the payment when ANY of these is true:
        //   1. amount is null/missing (gateway didn't echo it — trust the
        //      success code instead, since we already stored the amount
        //      when creating the intent)
        //   2. amount === intent.amount_minor (exact match)
        //   3. amount * 10 === intent.amount_minor (Toman vs Rial tolerance)
        //
        // We only fail when ZarinPay returned a DIFFERENT amount that
        // isn't a 10x multiple of what we expected.
        if ($result->amountIrr !== null) {
            $expected = (int) $intent->amount_minor;
            $received = (int) $result->amountIrr;
            if ($received !== $expected && $received * 10 !== $expected) {
                return 'amount_mismatch';
            }
        }

        // ─── MERCHANT REFERENCE CHECK ────────────────────────────────────
        // ZarinPay SHOULD echo back the order_id we sent. But if ZarinPay
        // doesn't return it (null), we don't fail — the authority is the
        // unique payment token and that's what really matters. We only
        // fail when ZarinPay returned a DIFFERENT order_id than ours.
        if ($result->merchantReference !== null
            && ! hash_equals($intent->merchant_reference, $result->merchantReference)) {
            return 'merchant_reference_mismatch';
        }

        // ─── AUTHORITY CHECK ─────────────────────────────────────────────
        // The authority is the unique payment token ZarinPay issued when
        // we created the payment. It MUST match — if ZarinPay returns a
        // different authority, that's a real security issue.
        if ($result->authority === null || ! hash_equals($authority, $result->authority)) {
            return 'authority_mismatch';
        }

        return null;
    }

    private function markVerificationMismatch(
        PaymentIntent $intent,
        PaymentAttempt $attempt,
        ZarinPayVerifyResult $result,
        string $reason,
    ): PaymentIntent {
        return DB::transaction(function () use ($intent, $attempt, $result, $reason): PaymentIntent {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $locked->forceFill(['status' => PaymentStatus::ManualReview])->save();
            $lockedAttempt->forceFill([
                'verify_code' => isset($result->code) ? (string) $result->code : null,
                'provider_response' => $this->redactProviderPayload($result->raw),
            ])->save();

            $this->auditLogger->log(
                'payment.verification_mismatch',
                $locked,
                $locked,
                [],
                ['status' => PaymentStatus::ManualReview->value, 'reason' => $reason],
                $reason,
            );

            return $locked->refresh()->load('attempts');
        });
    }

    private function recordUnsuccessfulVerification(
        PaymentIntent $intent,
        PaymentAttempt $attempt,
        ZarinPayVerifyResult $result,
    ): PaymentIntent {
        $status = match ($result->code) {
            -55 => PaymentStatus::Pending,
            -1, -54 => PaymentStatus::Failed,
            default => PaymentStatus::ManualReview,
        };

        return DB::transaction(function () use ($intent, $attempt, $result, $status): PaymentIntent {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $locked->forceFill(['status' => $status])->save();
            $lockedAttempt->forceFill([
                'verify_code' => isset($result->code) ? (string) $result->code : null,
                'provider_response' => $this->redactProviderPayload($result->raw),
            ])->save();

            return $locked->refresh()->load('attempts');
        });
    }

    private function fundOrderFromSettledIntent(PaymentIntent $intent, LedgerTransaction $journal): void
    {
        if ($intent->order_id === null) {
            throw new PaymentException('Order payment intent is missing its order.');
        }

        $order = Order::query()->lockForUpdate()->findOrFail($intent->order_id);

        if ((int) $order->user_id !== (int) $intent->user_id) {
            throw new PaymentException('Payment intent owner does not match its order owner.');
        }

        if ($order->payment_status === OrderPaymentStatus::Paid) {
            $existingHold = DB::table('fund_holds')
                ->where('order_id', $order->getKey())
                ->where('ledger_transaction_id', $journal->getKey())
                ->exists();

            if (! $existingHold) {
                throw new PaymentException('Order is already paid by a different settlement.');
            }

            return;
        }

        if ($order->status !== OrderStatus::AwaitingPayment) {
            throw new PaymentException('Settled order is no longer awaiting payment.');
        }

        if (strtoupper($intent->currency) === 'IRR'
            && (int) $order->total_irr !== (int) $intent->amount_minor) {
            throw new PaymentException('Settled amount no longer matches the order total.');
        }

        $this->createFundHold($order, (int) $order->total_irr, $journal);
        $order->forceFill([
            'payment_status' => OrderPaymentStatus::Paid,
            'funding_mode' => $intent->provider.'_direct',
            'funded_at' => now(),
        ])->save();

        $this->campaignTransitions->transition(
            $order,
            OrderStatus::SupportReview,
            $intent,
            'direct_payment_settled',
        );
    }

    private function createFundHold(Order $order, int $amountIrr, LedgerTransaction $journal): void
    {
        $existing = DB::table('fund_holds')
            ->where('order_id', $order->getKey())
            ->where('ledger_transaction_id', $journal->getKey())
            ->exists();

        if (! $existing) {
            DB::table('fund_holds')->insert([
                'order_id' => $order->getKey(),
                'user_id' => $order->user_id,
                'amount_irr' => $amountIrr,
                'status' => 'active',
                'ledger_transaction_id' => $journal->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function createSettlementAttempt(PaymentIntent $intent, string $providerReference): PaymentAttempt
    {
        $attributes = [
            'payment_intent_id' => $intent->getKey(),
            'provider_reference' => $providerReference,
        ];

        if ($intent->provider === 'zarinpay') {
            $attributes['authority'] = $providerReference;
        }

        return PaymentAttempt::create($attributes);
    }

    private function markCreationUncertain(PaymentIntent $intent, Throwable $exception): void
    {
        DB::transaction(function () use ($intent, $exception): void {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
            $locked->forceFill([
                'status' => PaymentStatus::ManualReview,
                'metadata' => array_merge($locked->metadata ?? [], [
                    'create_error' => Str::limit($exception->getMessage(), 500, ''),
                ]),
            ])->save();
        });
    }

    private function markVerificationUncertain(
        PaymentIntent $intent,
        PaymentAttempt $attempt,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($intent, $attempt, $exception): void {
            $locked = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->getKey());
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $locked->forceFill(['status' => PaymentStatus::ManualReview])->save();
            $lockedAttempt->forceFill([
                'provider_response' => ['verification_error' => Str::limit($exception->getMessage(), 500, '')],
            ])->save();
        });
    }

    private function assertMerchantReference(string $merchantReference): void
    {
        if ($merchantReference === ''
            || mb_strlen($merchantReference) > 100
            || preg_match('/^[A-Za-z0-9._:-]+$/', $merchantReference) !== 1) {
            throw new PaymentException('Merchant reference has an invalid format.');
        }
    }

    private function assertCallbackUrl(string $callbackUrl): void
    {
        $parts = parse_url($callbackUrl);

        if (filter_var($callbackUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new PaymentException('A valid HTTP(S) callback URL is required.');
        }

        if (! app()->environment(['local', 'testing']) && strtolower((string) $parts['scheme']) !== 'https') {
            throw new PaymentException('Production payment callbacks must use HTTPS.');
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function redactProviderPayload(array $payload): array
    {
        $blocked = ['api_key', 'token', 'secret', 'signature', 'pan', 'card_number', 'cvv', 'password'];

        $redact = function (array $values) use (&$redact, $blocked): array {
            foreach ($values as $key => $value) {
                $keyName = strtolower((string) $key);

                if (collect($blocked)->contains(fn (string $needle): bool => str_contains($keyName, $needle))) {
                    $values[$key] = '[REDACTED]';
                } elseif (is_array($value)) {
                    $values[$key] = $redact($value);
                }
            }

            return $values;
        };

        return $redact($payload);
    }

    private function safeProviderName(string $provider): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($provider));

        return trim((string) $safe, '_') ?: 'unknown';
    }

    private function isTrustedZarinPayUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = (array) config('services.zarinpay.payment_hosts', ['zarinmee.ir']);
        $trustedHost = collect($allowedHosts)->contains(
            fn (mixed $allowed): bool => $host === strtolower((string) $allowed)
                || str_ends_with($host, '.'.strtolower((string) $allowed)),
        );

        return ($parts['scheme'] ?? null) === 'https'
            && $host !== ''
            && $trustedHost
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }
}
