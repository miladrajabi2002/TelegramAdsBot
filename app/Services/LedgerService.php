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
            // … the rest of the original method is intentionally preserved.
            return DB::transaction(function () use ($type, $idempotencyKey, $description, $normalized, $reference, $createdByAdminId) {
                $transaction = LedgerTransaction::create([
                    'type' => $type,
                    'idempotency_key' => $idempotencyKey,
                    'description' => $description,
                    'reference_type' => $reference ? $reference->getMorphClass() : null,
                    'reference_id' => $reference?->getKey(),
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

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = LedgerTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * Find (or create) a ledger account for an owner + currency + type.
     */
    public function findOrCreateAccount(
        ?Model $owner,
        ?string $ownerType,
        string $currency,
        string $type,
        string $normalBalance,
        string $name,
    ): LedgerAccount {
        return LedgerAccount::firstOrCreate(
            array_filter([
                'owner_type' => $owner?->getMorphClass() ?? $ownerType,
                'owner_id' => $owner?->getKey(),
                'type' => $type,
                'currency' => $currency,
            ]),
            [
                'name' => $name,
                'normal_balance' => $normalBalance,
            ],
        );
    }

    public function findOrCreateUserAccount(Model $user, string $type, string $currency, string $normalBalance, ?string $name = null): LedgerAccount
    {
        return $this->findOrCreateAccount(
            $user,
            null,
            $currency,
            $type,
            $normalBalance,
            $name ?? "User {$type}",
        );
    }

    public function findOrCreateSystemAccount(string $type, string $currency, string $normalBalance, ?string $name = null): LedgerAccount
    {
        return $this->findOrCreateAccount(
            null,
            null,
            $currency,
            $type,
            $normalBalance,
            $name ?? "System {$type}",
        );
    }

    /**
     * Backward-compatible alias for findOrCreateUserAccount().
     *
     * Older call sites (PaymentService) pass arguments as
     *   accountFor(user, currency, type, normalBalance, name)
     * — i.e. `currency` and `type` are SWAPPED compared to the canonical
     * signature findOrCreateUserAccount(user, type, currency, normalBalance, name).
     * This alias keeps those call sites working without touching them.
     */
    public function accountFor(Model $user, string $currency, string $type, string $normalBalance, ?string $name = null): LedgerAccount
    {
        return $this->findOrCreateUserAccount($user, $type, $currency, $normalBalance, $name);
    }

    /**
     * Backward-compatible alias for findOrCreateSystemAccount().
     *
     * Older call sites (PaymentService) pass arguments as
     *   systemAccount(currency, type, normalBalance, name)
     * — i.e. `currency` and `type` are SWAPPED compared to the canonical
     * signature findOrCreateSystemAccount(type, currency, normalBalance, name).
     * This alias keeps those call sites working without touching them.
     */
    public function systemAccount(string $currency, string $type, string $normalBalance, ?string $name = null): LedgerAccount
    {
        return $this->findOrCreateSystemAccount($type, $currency, $normalBalance, $name);
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
     * Fetch ALL wallet balances for an owner in a single grouped query.
     *
     * This replaces the N+1 pattern of calling ->balance() on each
     * LedgerAccount individually, which fired 2 SUM queries per account
     * per page load (3 accounts × 2 = 6 queries on the home page).
     *
     * Returns: ['wallet_available' => int, 'wallet_reserved' => int, 'ad_credit_restricted' => int, ...]
     *
     * @return array<string, int>
     */
    public function balancesFor(Model $owner): array
    {
        $rows = DB::table('ledger_accounts')
            ->join('ledger_entries', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.owner_type', $owner->getMorphClass())
            ->where('ledger_accounts.owner_id', $owner->getKey())
            ->selectRaw(
                'ledger_accounts.type AS type, '.
                'ledger_accounts.normal_balance AS normal_balance, '.
                'SUM(CASE WHEN ledger_entries.direction = "credit" THEN ledger_entries.amount_minor ELSE 0 END) AS credits, '.
                'SUM(CASE WHEN ledger_entries.direction = "debit"  THEN ledger_entries.amount_minor ELSE 0 END) AS debits'
            )
            ->groupBy('ledger_accounts.type', 'ledger_accounts.normal_balance')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $net = $row->normal_balance === 'credit'
                ? (int) $row->credits - (int) $row->debits
                : (int) $row->debits - (int) $row->credits;
            $balances[$row->type] = $net;
        }

        return $balances;
    }

    /**
     * Bulk-fetch wallet balances for many owners in ONE grouped query.
     *
     * Returns: [user_id => ['wallet_available' => int, 'wallet_reserved' => int, …], …]
     *
     * @param  iterable<Model>  $owners
     * @return array<int, array<string, int>>
     */
    public function balancesForMany(iterable $owners, string $ownerType): array
    {
        $ids = collect($owners)->map(fn ($o) => $o->getKey())->all();
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('ledger_accounts')
            ->join('ledger_entries', 'ledger_entries.ledger_account_id', '=', 'ledger_accounts.id')
            ->where('ledger_accounts.owner_type', $ownerType)
            ->whereIn('ledger_accounts.owner_id', $ids)
            ->selectRaw(
                'ledger_accounts.owner_id AS owner_id, '.
                'ledger_accounts.type AS type, '.
                'ledger_accounts.normal_balance AS normal_balance, '.
                'SUM(CASE WHEN ledger_entries.direction = "credit" THEN ledger_entries.amount_minor ELSE 0 END) AS credits, '.
                'SUM(CASE WHEN ledger_entries.direction = "debit"  THEN ledger_entries.amount_minor ELSE 0 END) AS debits'
            )
            ->groupBy('ledger_accounts.owner_id', 'ledger_accounts.type', 'ledger_accounts.normal_balance')
            ->get();

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
        }
        foreach ($rows as $row) {
            $net = $row->normal_balance === 'credit'
                ? (int) $row->credits - (int) $row->debits
                : (int) $row->debits - (int) $row->credits;
            $out[(int) $row->owner_id][$row->type] = $net;
        }

        return $out;
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
                'account_id' => $account->getKey(),
                'direction' => $direction,
                'amount_minor' => $amount,
                'currency' => $currency,
            ];
        }

        return $normalized;
    }

    private function assertBalanced(array $normalized): void
    {
        $totals = [];
        foreach ($normalized as $entry) {
            $totals[$entry['currency']] ??= ['debit' => 0, 'credit' => 0];
            $totals[$entry['currency']][$entry['direction']] += $entry['amount_minor'];
        }
        foreach ($totals as $currency => $sides) {
            if ($sides['debit'] !== $sides['credit']) {
                throw new DomainException("Ledger transaction is not balanced for {$currency} (debit={$sides['debit']} credit={$sides['credit']}).");
            }
        }
    }
}
