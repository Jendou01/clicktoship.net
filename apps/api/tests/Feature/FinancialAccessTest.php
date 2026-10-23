<?php

namespace Tests\Feature;

use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\ManagementTestCase;

class FinancialAccessTest extends ManagementTestCase
{
    use DatabaseMigrations;

    public function test_wallet_reads_are_scoped_and_funding_remains_closed(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $other = $this->organization($this->user());
        $w = app(Ledger::class)->provision($m);
        $ow = app(Ledger::class)->provision($other);
        $this->bearer($this->token($u, $m, ['wallet.read']), 'GET', '/api/v1/wallets')->assertOk()->assertJsonPath('data.funding_enabled', false)->assertJsonPath('data.wallets.0.available', '0');
        $token = $this->token($u, $m, ['wallet.transactions.read']);
        $this->bearer($token, 'GET', "/api/v1/wallets/$ow/transactions")->assertNotFound();
        $this->bearer($token, 'GET', "/api/v1/wallets/$w/holds")->assertForbidden();
        $this->bearer($token, 'POST', "/api/v1/wallets/$w/topups", ['amount' => '1000'])->assertNotFound();
        $this->getJson('/api/v1/wallets')->assertUnauthorized();
    }

    public function test_open_wallet_is_zero_balance_csrf_protected_and_currency_restricted(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $this->login($u);
        $this->select($m);
        $this->browser('POST', '/api/v1/wallets', ['currency' => 'SAR'], false)->assertStatus(419);
        $this->browser('POST', '/api/v1/wallets', ['currency' => 'KWD'])->assertUnprocessable();
        $this->browser('POST', '/api/v1/wallets', ['currency' => 'SAR', 'merchant_id' => $m])->assertUnprocessable();
        $this->browser('POST', '/api/v1/wallets', ['currency' => 'SAR'])->assertOk()->assertJsonPath('data.wallets.0.posted', '0');
        $this->browser('POST', '/api/v1/wallets', ['currency' => 'SAR'])->assertOk();
        $this->assertSame(1, DB::table('wallets')->count());
        $this->assertSame(0, DB::table('ledger_transactions')->count());
    }

    public function test_financial_admin_needs_fresh_mfa_exact_target_grant_and_independent_approval(): void
    {
        $m = $this->organization($this->user());
        $w = app(Ledger::class)->provision($m);
        $a = $this->platformUser();
        $b = $this->platformUser();
        $grant = $this->grant($a, $b, $m, ['platform.wallet.view', 'platform.finance.correct', 'platform.finance.reconcile']);
        $this->browser('GET', "/api/v1/admin/merchants/$m/wallets?access_grant=$grant")->assertOk();
        $this->browser('GET', "/api/v1/admin/merchants/$m/wallets")->assertUnprocessable();
        $d = ['access_grant' => $grant, 'wallet_id' => $w, 'kind' => 'adjust_credit', 'amount' => '500', 'business_key' => 'correction-1', 'request_key' => 'request-key-00001', 'reason' => 'Synthetic correction approval'];
        $id = $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests", $d)->assertCreated()->json('data.id');
        $this->assertSame($id, $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests", $d)->assertCreated()->json('data.id'));
        $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests/$id/approve", ['access_grant' => $grant, 'version' => 1, 'request_key' => 'approve-key-00001'])->assertForbidden();
        $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests/$id/execute", ['access_grant' => $grant, 'version' => 1, 'request_key' => 'execute-key-00001'])->assertForbidden();
        $this->assertSame(0, DB::table('ledger_transactions')->count());
        DB::table('auth_sessions')->where('user_id', $a->id)->update(['mfa_verified_at' => now()->subMinutes(16)]);
        $this->browser('GET', "/api/v1/admin/merchants/$m/wallets?access_grant=$grant")->assertForbidden();
    }

    public function test_revoked_membership_token_cannot_read_wallet_after_reactivation(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $t = $this->token($u, $m, ['wallet.read']);
        DB::table('users')->where('id', $u->id)->update(['auth_version' => $u->auth_version + 1]);
        $this->bearer($t, 'GET', '/api/v1/wallets')->assertUnauthorized();
    }

    public function test_statement_cutoff_does_not_include_later_postings(): void
    {
        $u = $this->user();
        $m = $this->organization($u);
        $l = app(Ledger::class);
        $w = $l->provision($m);
        $l->command($m, $w, 'synthetic', '1000', 'first', 'first', 'test');
        $l->command($m, $w, 'synthetic', '2000', 'second', 'second', 'test');
        $this->bearer($this->token($u, $m, ['wallet.statements.read']), 'GET', "/api/v1/wallets/$w/statement?cutoff=1")->assertOk()->assertJsonCount(1, 'data.transactions')->assertJsonPath('data.opening', '0')->assertJsonPath('data.closing', '1000');
    }

    public function test_independent_approved_adjustment_executes_once_and_revocation_blocks_stale_authority(): void
    {
        config(['finance.corrections_enabled' => true]); // Test-only accountant gate; never enabled by deployment config.
        $m = $this->organization($this->user());
        $w = app(Ledger::class)->provision($m);
        $a = $this->platformUser();
        $b = $this->platformUser();
        $ga = $this->grant($a, $b, $m, ['platform.wallet.view', 'platform.finance.correct']);
        $jarA = $this->jar;
        $id = $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests", ['access_grant' => $ga, 'wallet_id' => $w, 'kind' => 'adjust_credit', 'amount' => '500', 'business_key' => 'approved-correction', 'request_key' => 'request-key-00001', 'reason' => 'Synthetic independently reviewed correction'])->assertCreated()->json('data.id');
        $this->travel(31)->seconds();
        $gb = $this->grant($b, $a, $m, ['platform.wallet.view', 'platform.finance.correct']);
        $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests/$id/approve", ['access_grant' => $gb, 'version' => 1, 'request_key' => 'approve-key-00001'])->assertOk();
        $this->jar = $jarA;
        $body = ['access_grant' => $ga, 'version' => 2, 'request_key' => 'execute-key-00001'];
        $first = $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests/$id/execute", $body)->assertOk()->json('data.operation');
        $this->assertSame($first, $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests/$id/execute", $body)->assertOk()->json('data.operation'));
        $this->assertDatabaseHas('wallet_balances', ['wallet_id' => $w, 'posted' => 500]);
        $this->assertSame(1, DB::table('ledger_transactions')->count());
        DB::table('auth_sessions')->where('user_id', $b->id)->update(['revoked_at' => now()]);
        $this->browser('POST', "/api/v1/admin/merchants/$m/financial-requests", ['access_grant' => $ga, 'wallet_id' => $w, 'kind' => 'adjust_credit', 'amount' => '100', 'business_key' => 'revoked-correction', 'request_key' => 'request-key-00002', 'reason' => 'Synthetic revoked authority correction'])->assertForbidden();
    }
}
