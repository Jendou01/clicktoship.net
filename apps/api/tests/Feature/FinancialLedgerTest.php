<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use App\Modules\WalletBilling\Application\Reconciliation;
use App\Modules\WalletBilling\Domain\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ManagementTestCase;
use Tests\Support\SyntheticPaymentProvider;

class FinancialLedgerTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_full_hold_capture_release_refund_and_replay(): void
    {
        $merchant = $this->organization($this->user());
        $l = app(Ledger::class);
        $wallet = $l->provision($merchant);
        $l->command($merchant, $wallet, 'synthetic', '10000', 'seed', 'seed', 'test');
        $hold = $l->command($merchant, $wallet, 'reserve', '6000', 'reserve', 'r', 'test', expires: now()->addHour()->toIso8601String());
        $capture = $l->command($merchant, $wallet, 'capture', '4000', 'capture', 'c', 'test', $hold['hold_id']);
        $l->command($merchant, $wallet, 'release', '2000', 'release', 'l', 'test', $hold['hold_id']);
        $refund = $l->command($merchant, $wallet, 'refund', '1000', 'refund', 'f', 'test', $capture['transaction_id']);
        $this->assertSame($refund, $l->command($merchant, $wallet, 'refund', '1000', 'refund', 'f2', 'test', $capture['transaction_id']));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $wallet, 'posted' => 7000, 'reserved' => 0]);
        $this->assertSame(3, DB::table('ledger_transactions')->count());
        $this->assertSame(6, DB::table('ledger_entries')->count());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    private function funded(): array
    {
        $m = $this->organization($this->user());
        $l = app(Ledger::class);
        $w = $l->provision($m);
        $l->command($m, $w, 'synthetic', '10000', 'seed', 'seed', 'test');

        return [$l, $m, $w];
    }

    private function denied(callable $command): void
    {
        try {
            $command();
            $this->fail('Financial command unexpectedly succeeded');
        } catch (\PDOException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_overspend_and_changed_request_payload_fail_without_extra_effects(): void
    {
        [$l,$m,$w] = $this->funded();
        $expiry = now()->addHour()->toIso8601String();
        $l->command($m, $w, 'reserve', '7000', 'one', 'one', 'test', expires: $expiry);
        $this->denied(fn () => $l->command($m, $w, 'reserve', '7000', 'two', 'two', 'test', expires: $expiry));
        $this->denied(fn () => $l->command($m, $w, 'reserve', '100', 'one', 'one', 'test', expires: $expiry));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'posted' => 10000, 'reserved' => 7000]);
        $this->assertSame(1, DB::table('balance_holds')->count());
    }

    public function test_unknown_protected_hold_cannot_expire_or_release(): void
    {
        [$l,$m,$w] = $this->funded();
        $h = $l->command($m, $w, 'reserve', '6000', 'hold', 'h', 'test', expires: now()->addSecond()->toIso8601String());
        $l->command($m, $w, 'protect', '0', 'dispatch', 'd', 'test', $h['hold_id']);
        $l->command($m, $w, 'unknown', '0', 'unknown', 'u', 'test', $h['hold_id']);
        $this->travel(2)->seconds();
        foreach (['expire', 'release', 'capture'] as $kind) {
            $this->denied(fn () => $l->command($m, $w, $kind, '6000', $kind, $kind, 'test', $h['hold_id']));
        }
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'reserved' => 6000]);
    }

    public function test_refund_caps_and_dependent_reversal_are_enforced(): void
    {
        [$l,$m,$w] = $this->funded();
        $h = $l->command($m, $w, 'reserve', '6000', 'h', 'h', 'test', expires: now()->addHour()->toIso8601String());
        $c = $l->command($m, $w, 'capture', '6000', 'c', 'c', 'test', $h['hold_id']);
        $l->command($m, $w, 'refund', '4000', 'f', 'f', 'test', $c['transaction_id']);
        $this->denied(fn () => $l->command($m, $w, 'refund', '3000', 'f2', 'f2', 'test', $c['transaction_id']));
        $this->denied(fn () => $l->command($m, $w, 'reverse', '0', 'reverse', 'reverse', 'test', $c['transaction_id']));
    }

    public function test_full_reversal_cannot_repeat_under_another_business_key(): void
    {
        [$l,$m,$w] = $this->funded();
        $t = DB::table('ledger_transactions')->value('id');
        $l->command($m, $w, 'reverse', '0', 'reversal', 'r', 'test', $t);
        $this->denied(fn () => $l->command($m, $w, 'reverse', '0', 'another', 'r2', 'test', $t));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'posted' => 0]);
    }

    public function test_transaction_failure_rolls_back_money_dedupe_and_outbox_then_retry_succeeds(): void
    {
        [$l,$m,$w] = $this->funded();
        try {
            DB::transaction(function () use ($l, $m, $w) {
                $l->command($m, $w, 'synthetic', '100', 'adjust', 'a', 'test');
                throw new \RuntimeException('simulated crash before commit');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseMissing('financial_operations', ['business_key' => 'adjust']);
        $this->assertSame(1, DB::table('financial_outbox')->count());
        $l->command($m, $w, 'synthetic', '100', 'adjust', 'a', 'test');
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'posted' => 10100]);
        $reconciler = app(Reconciliation::class);
        $this->assertTrue($reconciler->run($m, $w)['matches']);
        $this->assertSame(2, $reconciler->drain());
        $this->assertSame(0, $reconciler->drain());
    }

    public function test_history_cannot_be_changed_and_restricted_runtime_cannot_write_money_directly(): void
    {
        [$l,$m,$w] = $this->funded();
        foreach (['ledger_entries', 'ledger_transactions', 'financial_operations', 'balance_hold_events', 'financial_outbox'] as $table) {
            $this->denied(fn () => DB::statement("TRUNCATE $table CASCADE"));
        }
        DB::statement('SET ROLE cts_finance_runtime');
        try {
            try {
                DB::table('wallet_balances')->where('wallet_id', $w)->update(['posted' => 50000]);
                $this->fail('Direct runtime money write allowed');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
            $digest = hash('sha256', 'runtime');
            $r = DB::selectOne('SELECT finance_command(?::uuid,?::uuid,?,?::bigint,NULL,?,?,?,?,?::timestamptz) AS r', [$m, $w, 'reserve', 100, 'runtime', $digest, 'test', $digest, now()->addHour()]);
            $this->assertNotNull($r->r);
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    public function test_synthetic_event_replay_is_permanently_deduplicated(): void
    {
        $m = $this->organization($this->user());
        $w = app(Ledger::class)->provision($m);
        $p = new SyntheticPaymentProvider;
        $i = $p->initiate($m, $w, new Money('1200'), 'test-intent');
        $p->event($i['id'], 'pending', 'pending');
        $first = $p->event($i['id'], 'confirmed', 'confirmed');
        $this->assertSame($first, $p->event($i['id'], 'confirmed', 'confirmed'));
        $this->assertSame($first, $p->event($i['id'], 'another-confirmation', 'confirmed'));
        $p->event($i['id'], 'late-pending', 'pending');
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'posted' => 1200]);
        $this->assertSame(1, DB::table('ledger_transactions')->count());
        $this->expectException(\LogicException::class);
        $p->event($i['id'], 'confirmed', 'rejected');
    }

    public function test_raw_unbalanced_empty_and_cross_tenant_journals_cannot_commit(): void
    {
        [$l,$m,$w] = $this->funded();
        $source = DB::table('ledger_transactions')->first();
        $other = $this->organization($this->user());
        $ow = $l->provision($other);
        $otherAccount = DB::table('wallets')->where('id', $ow)->value('account_id');
        $this->denied(fn () => DB::transaction(function () use ($source, $otherAccount) {
            DB::table('ledger_entries')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $source->merchant_id, 'transaction_id' => $source->id, 'account_id' => $otherAccount, 'currency' => 'SAR', 'side' => 'debit', 'amount' => 1]);
        }));
        $this->assertSame(2, DB::table('ledger_entries')->count());
    }

    public function test_empty_and_unbalanced_new_journals_are_rejected_at_commit(): void
    {
        [$l,$m,$w] = $this->funded();
        $original = DB::table('ledger_transactions')->first();
        foreach ([0, 1] as $entryCount) {
            $this->denied(fn () => DB::transaction(function () use ($m, $w, $entryCount) {
                $op = (string) Str::uuid();
                $tid = (string) Str::uuid();
                DB::table('financial_operations')->insert(['id' => $op, 'merchant_id' => $m, 'wallet_id' => $w, 'currency' => 'SAR', 'kind' => 'invalid', 'business_key' => $op, 'digest' => str_repeat('0', 64), 'result' => '{}']);
                DB::table('ledger_transactions')->insert(['id' => $tid, 'merchant_id' => $m, 'wallet_id' => $w, 'currency' => 'SAR', 'operation_id' => $op, 'template' => 'invalid', 'template_version' => 1]);
                if ($entryCount) {
                    DB::table('ledger_entries')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $m, 'transaction_id' => $tid, 'account_id' => DB::table('wallets')->where('id', $w)->value('account_id'), 'currency' => 'SAR', 'side' => 'credit', 'amount' => 1]);
                }
            }));
        }
        $this->assertSame(1, DB::table('ledger_transactions')->count());
    }

    public function test_adjustments_without_independent_approval_are_rejected_even_internally(): void
    {
        [$l,$m,$w] = $this->funded();
        $this->denied(fn () => $l->command($m, $w, 'adjust_credit', '100', 'unapproved', 'unapproved', 'test'));
    }

    public function test_only_sar_is_enabled_and_minor_units_are_exact(): void
    {
        $m = $this->organization($this->user());
        $this->assertSame(['SAR'], DB::table('financial_currencies')->where('enabled', true)->pluck('code')->all());
        foreach (['1.2', '1e2', '-1', '1000000000001', '01'] as $bad) {
            try {
                new Money($bad);
                $this->fail('Invalid minor amount accepted');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1000000000000, (new Money('1000000000000', 'KWD'))->minor);
        $this->expectException(HttpException::class);
        app(Ledger::class)->provision($m, 'KWD');
    }
}
