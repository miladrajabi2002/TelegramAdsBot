<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LedgerService
{
    /**
     * @param  list<array{account: LedgerAccount, direction: 'debit'|'credit', amount_minor: int, currency?: string}>  $entries
     */
    public function post(
        string $type,
        string $idempotencyKey,
        string $description,
        array $entries,
        ?Model $reference = null,
        ?int $createdByAdminId = null,
    ): LedgerTransaction {
        $type = trim($type);
        $idempotencyKey = trim($idempotencyKey);
        $description = trim($description);

        if ($type === '' || mb_strlen($type) > 40) {
            throw new DomainException('Ledger transaction type is required and may not exceed 40 characters.');
        }

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 160) {
            throw new DomainException('A valid ledger idempotency key is required.');
        }

        if ($description === '') {
            throw new DomainException('Ledger transaction description is required.');
        }

        if ($reference !== null && ! $reference->exists) {
            throw new DomainException('Ledger reference must be a persisted model.');
        }

        $normalized = $this->normalizeEntries($entries);
        $this->assertBalanced($normalized);

        try {
            return DB::transaction(function () use (
                $type,
                $idempotencyKey,
                $description,
                $normalized,
                $reference,
                $createdByAdminId,
            ): LedgerTransaction {
                $existing = LedgerTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $this->assertEquivalent($existing, $type, $normalized, $reference);

                    return $existing->load('entries');
                }

                $accountIds = collect($normalized)->pluck('account_id')->unique()->sort()->values();
                $accounts = LedgerAccount::query()
                    ->whereKey($accountIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($accounts->count() !== $accountIds->count()) {
                    throw new DomainException('One or more ledger accounts do not exist.');
                }

                foreach ($normalized as $entry) {
                    $account = $accounts->get($entry['account_id']);

                    if (! $account->is_active) {
                        throw new DomainException("Ledger account [{$account->id}] is inactive.");
                    }

                    if (strtoupper($account->currency) !== $entry['currency']) {
                        throw new DomainException("Ledger account [{$account->id}] has a currency mismatch.");
                    }
                }

                $transaction = LedgerTransaction::create([
                    'public_id' => (string) Str::uuid(),
                    'type' => $type,
                    'reference_type' => $reference?->getMorphClass(),
                    'reference_id' => $reference?->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'description' => $description,
                    'created_by_admin_id' => $createdByAdminId,
                ]);

                foreach ($normalized as $entry) {
                    LedgerEntry::create([
                        'ledger_transaction_id' => $transaction->getKey(),
                        'ledger_account_id' => $entry['account_id'],
                        'direction' => $entry['direction'],
                        'amount_minor' => $entry['amount_minor'],
                        'currency' => $entry['currency'],
                    ]);
                }

                $transaction->load('entries');
                $this->assertPersistedTransactionBalanced($transaction);

                return $transaction;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request may have committed the same idempotency key.
            $existing = LedgerTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $this->assertEquivalent($existing, $type, $normalized, $reference);

            return $existing->load('entries');
        }
    }

    public function accountFor(
        Model $owner,
        string $currency,
        string $type,
        string $normalBalance,
        ?string $name = null,
    ): LedgerAccount {
        if (! $owner->exists) {
            throw new DomainException('Ledger account owner must be persisted.');
        }

        return $this->findOrCreateAccount(
            $owner->getMorphClass(),
            (int) $owner->getKey(),
            $currency,
            $type,
            $normalBalance,
            $name ?? sprintf('%s %s', class_basename($owner), $type),
        );
    }

    public function systemAccount(
        string $currency,
        string $type,
        string $normalBalance,
        ?string $name = null,
    ): LedgerAccount {
        return $this->findOrCreateAccount(
            null,
            null,
            $currency,
            $type,
            $normalBalance,
            $name ?? "System {$type}",
        );
    }

    public function balance(LedgerAccount $account): int
    {
        $debits = (int) LedgerEntry::query()
            ->where('ledger_account_id', $account->getKey())
            ->where('direction', 'debit')
            ->sum('amount_minor');
        $credits = (int) LedgerEntry::query()
            ->where('ledger_account_id', $account->getKey())
            ->where('direction', 'credit')
            ->sum('amount_minor');

        return $account->normal_balance === 'credit'
            ? $credits - $debits
            : $debits - $credits;
    }

    /**
     * @param  list<array{account: LedgerAccount, direction: 'debit'|'credit', amount_minor: int, currency?: string}>  $entries
     * @return list<array{account_id: int, direction: string, amount_minor: int, currency: string}>
     */
    private function normalizeEntries(array $entries): array
    {
        if (count($entries) < 2) {
            throw new DomainException('A double-entry transaction requires at least two entries.');
        }

        $normalized = [];

        foreach ($entries as $index => $entry) {
            $account = $entry['account'] ?? null;
            $direction = $entry['direction'] ?? null;
            $amount = $entry['amount_minor'] ?? null;

            if (! $account instanceof LedgerAccount || ! $account->exists) {
                throw new DomainException("Ledger entry [{$index}] requires a persisted account.");
            }

            if (! in_array($direction, ['debit', 'credit'], true)) {
                throw new DomainException("Ledger entry [{$index}] has an invalid direction.");
            }

            if (! is_int($amount) || $amount <= 0) {
                throw new DomainException("Ledger entry [{$index}] amount must be a positive integer.");
            }

            $currency = strtoupper((string) ($entry['currency'] ?? $account->currency));

            if ($currency === '' || mb_strlen($currency) > 12) {
                throw new DomainException("Ledger entry [{$index}] has an invalid currency.");
            }

            $normalized[] = [
                'account_id' => (int) $account->getKey(),
                'direction' => $direction,
                'amount_minor' => $amount,
                'currency' => $currency,
            ];
        }

        return $normalized;
    }

    /** @param list<array{account_id: int, direction: string, amount_minor: int, currency: string}> $entries */
    private function assertBalanced(array $entries): void
    {
        $totals = [];

        foreach ($entries as $entry) {
            $totals[$entry['currency']] ??= 0;
            $totals[$entry['currency']] += $entry['direction'] === 'debit'
                ? $entry['amount_minor']
                : -$entry['amount_minor'];
        }

        foreach ($totals as $currency => $total) {
            if ($total !== 0) {
                throw new DomainException("Ledger entries are not balanced for currency [{$currency}].");
            }
        }
    }

    private function assertPersistedTransactionBalanced(LedgerTransaction $transaction): void
    {
        $entries = $transaction->entries->map(static fn (LedgerEntry $entry): array => [
            'account_id' => (int) $entry->ledger_account_id,
            'direction' => $entry->direction,
            'amount_minor' => (int) $entry->amount_minor,
            'currency' => strtoupper($entry->currency),
        ])->all();

        $this->assertBalanced($entries);
    }

    /**
     * @param  list<array{account_id: int, direction: string, amount_minor: int, currency: string}>  $expectedEntries
     */
    private function assertEquivalent(
        LedgerTransaction $existing,
        string $type,
        array $expectedEntries,
        ?Model $reference,
    ): void {
        $existing->loadMissing('entries');

        $referenceMatches = $existing->reference_type === $reference?->getMorphClass()
            && (string) ($existing->reference_id ?? '') === (string) ($reference?->getKey() ?? '');

        if ($existing->type !== $type || ! $referenceMatches) {
            throw new DomainException('Ledger idempotency key was reused for a different transaction.');
        }

        $actualEntries = $existing->entries->map(static fn (LedgerEntry $entry): array => [
            'account_id' => (int) $entry->ledger_account_id,
            'direction' => $entry->direction,
            'amount_minor' => (int) $entry->amount_minor,
            'currency' => strtoupper($entry->currency),
        ])->all();

        $sort = static function (array &$items): void {
            usort($items, static fn (array $left, array $right): int => [
                $left['account_id'], $left['currency'], $left['direction'], $left['amount_minor'],
            ] <=> [
                $right['account_id'], $right['currency'], $right['direction'], $right['amount_minor'],
            ]);
        };

        $sort($actualEntries);
        $sort($expectedEntries);

        if ($actualEntries !== $expectedEntries) {
            throw new DomainException('Ledger idempotency key was reused with different entries.');
        }
    }

    private function findOrCreateAccount(
        ?string $ownerType,
        ?int $ownerId,
        string $currency,
        string $type,
        string $normalBalance,
        string $name,
    ): LedgerAccount {
        $currency = strtoupper(trim($currency));
        $type = trim($type);
        $normalBalance = strtolower(trim($normalBalance));

        if ($currency === '' || mb_strlen($currency) > 12) {
            throw new DomainException('Ledger account currency is invalid.');
        }

        if ($type === '' || mb_strlen($type) > 40) {
            throw new DomainException('Ledger account type is invalid.');
        }

        if (! in_array($normalBalance, ['debit', 'credit'], true)) {
            throw new DomainException('Ledger account normal balance must be debit or credit.');
        }

        $account = LedgerAccount::query()->firstOrCreate([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'currency' => $currency,
            'type' => $type,
        ], [
            'normal_balance' => $normalBalance,
            'name' => $name,
            'is_active' => true,
        ]);

        if ($account->normal_balance !== $normalBalance) {
            throw new DomainException('Existing ledger account has a different normal balance.');
        }

        return $account;
    }
}
