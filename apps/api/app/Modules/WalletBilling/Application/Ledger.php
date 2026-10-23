<?php

namespace App\Modules\WalletBilling\Application;

use App\Modules\Identity\Application\Audit;
use App\Modules\WalletBilling\Domain\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal financial commands. Never accept untrusted merchant/account identifiers from HTTP. */
final class Ledger
{
    public function provision(string $merchant, string $currency = 'SAR'): string
    {
        return DB::transaction(function () use ($merchant, $currency) {
            abort_unless(DB::table('merchants')->where('id', $merchant)->where('status', 'active')->lockForUpdate()->first(), 404);
            abort_unless(DB::table('financial_currencies')->where('code', $currency)->where('enabled', true)->exists(), 422);
            if ($id = DB::table('wallets')->where('merchant_id', $merchant)->where('currency', $currency)->value('id')) {
                return $id;
            }
            $account = (string) Str::uuid();
            $wallet = (string) Str::uuid();
            DB::table('ledger_accounts')->insert(['id' => $account, 'merchant_id' => $merchant, 'currency' => $currency, 'purpose' => 'wallet', 'class' => 'liability']);
            foreach (['service_clearing' => 'clearing', 'adjustment_expense' => 'expense', 'adjustment_income' => 'income', 'test_clearing' => 'clearing'] as $purpose => $class) {
                DB::table('ledger_accounts')->insertOrIgnore(['id' => (string) Str::uuid(), 'merchant_id' => null, 'currency' => $currency, 'purpose' => $purpose, 'class' => $class]);
            }
            DB::table('wallets')->insert(['id' => $wallet, 'merchant_id' => $merchant, 'currency' => $currency, 'account_id' => $account]);
            DB::table('wallet_balances')->insert(['wallet_id' => $wallet, 'merchant_id' => $merchant, 'currency' => $currency]);

            return $wallet;
        });
    }

    public function command(string $merchant, string $wallet, string $kind, string $amount, string $businessKey, string $requestKey, string $actorScope, ?string $reference = null, ?string $expires = null): array
    {
        $money = new Money($amount);
        $digest = hash('sha256', json_encode([$wallet, $kind, $amount, $businessKey, $reference, $expires], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($merchant, $wallet, $kind, $money, $businessKey, $requestKey, $actorScope, $reference, $expires, $digest) {
            $row = DB::selectOne('SELECT finance_command(?::uuid,?::uuid,?,?::bigint,?::uuid,?,?,?,?,?::timestamptz) AS result', [$merchant, $wallet, $kind, $money->minor, $reference, $businessKey, $digest, $actorScope, hash('sha256', $requestKey), $expires]);
            $result = json_decode($row->result, true, flags: JSON_THROW_ON_ERROR);
            if (! DB::table('audit_logs')->where('action', 'finance.command')->where('subject_id', $result['operation_id'])->exists()) {
                Audit::record('finance.command', null, $merchant, $result['operation_id']);
            }

            return $result;
        }, 3);
    }
}
