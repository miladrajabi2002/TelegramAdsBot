<?php

namespace Tests\Unit;

use App\Models\LedgerTransaction;
use App\Models\User;
use App\Services\LedgerService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_posts_a_balanced_double_entry_transaction_and_computes_balances(): void
    {
        $service = new LedgerService;
        $user = $this->user();
        $cash = $service->systemAccount('IRR', 'gateway_test_clearing', 'debit');
        $wallet = $service->accountFor($user, 'IRR', 'wallet_available', 'credit');

        $transaction = $service->post(
            'wallet_top_up',
            'ledger-test-1',
            'Verified test top-up',
            [
                ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 150_000],
                ['account' => $wallet, 'direction' => 'credit', 'amount_minor' => 150_000],
            ],
            $user,
        );

        $this->assertCount(2, $transaction->entries);
        $this->assertSame(150_000, $service->balance($cash));
        $this->assertSame(150_000, $service->balance($wallet));
    }

    public function test_an_unbalanced_transaction_is_rejected_without_writes(): void
    {
        $service = new LedgerService;
        $user = $this->user();
        $cash = $service->systemAccount('IRR', 'gateway_test_clearing', 'debit');
        $wallet = $service->accountFor($user, 'IRR', 'wallet_available', 'credit');

        try {
            $service->post('wallet_top_up', 'ledger-test-2', 'Invalid top-up', [
                ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 100],
                ['account' => $wallet, 'direction' => 'credit', 'amount_minor' => 99],
            ]);
            $this->fail('An unbalanced journal should throw.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not balanced', $exception->getMessage());
        }

        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_repeating_the_same_idempotency_key_returns_the_original_journal(): void
    {
        $service = new LedgerService;
        $user = $this->user();
        $cash = $service->systemAccount('IRR', 'gateway_test_clearing', 'debit');
        $wallet = $service->accountFor($user, 'IRR', 'wallet_available', 'credit');
        $entries = [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 700],
            ['account' => $wallet, 'direction' => 'credit', 'amount_minor' => 700],
        ];

        $first = $service->post('wallet_top_up', 'ledger-test-3', 'Top-up', $entries, $user);
        $second = $service->post('wallet_top_up', 'ledger-test-3', 'A harmless retry', $entries, $user);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, LedgerTransaction::query()->count());
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_reusing_an_idempotency_key_with_different_entries_is_rejected(): void
    {
        $service = new LedgerService;
        $user = $this->user();
        $cash = $service->systemAccount('IRR', 'gateway_test_clearing', 'debit');
        $wallet = $service->accountFor($user, 'IRR', 'wallet_available', 'credit');

        $service->post('wallet_top_up', 'ledger-test-4', 'Top-up', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 700],
            ['account' => $wallet, 'direction' => 'credit', 'amount_minor' => 700],
        ], $user);

        $this->expectException(DomainException::class);
        $service->post('wallet_top_up', 'ledger-test-4', 'Conflicting retry', [
            ['account' => $cash, 'direction' => 'debit', 'amount_minor' => 701],
            ['account' => $wallet, 'direction' => 'credit', 'amount_minor' => 701],
        ], $user);
    }

    private function user(): User
    {
        return User::create([
            'telegram_user_id' => 101,
            'display_name' => 'Ledger User',
            'locale' => 'fa',
        ]);
    }
}
