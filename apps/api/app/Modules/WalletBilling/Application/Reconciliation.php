<?php

namespace App\Modules\WalletBilling\Application;

use App\Modules\Identity\Application\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Reconciliation
{
    public function run(string $merchant, string $wallet, ?string $actor = null): array
    {
        return DB::transaction(function () use ($merchant, $wallet, $actor) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $w = DB::table('wallets')->where('merchant_id', $merchant)->where('id', $wallet)->first();
            abort_unless($w, 404);
            $b = DB::table('wallet_balances')->where('wallet_id', $wallet)->lockForUpdate()->first();
            $posted = DB::table('ledger_entries')->where('account_id', $w->account_id)->selectRaw("coalesce(sum(CASE WHEN side='credit' THEN amount::numeric ELSE -amount::numeric END),0) AS total")->value('total');
            $reserved = DB::table('balance_holds')->where('wallet_id', $wallet)->selectRaw('coalesce(sum(original::numeric-captured-released),0) AS total')->value('total');
            $matches = (string) $posted === (string) $b->posted && (string) $reserved === (string) $b->reserved;
            $id = (string) Str::uuid();
            DB::table('financial_reconciliation_runs')->insert(['id' => $id, 'merchant_id' => $merchant, 'wallet_id' => $wallet, 'currency' => $w->currency, 'cutoff' => $b->version, 'posted' => $b->posted, 'reserved' => $b->reserved, 'ledger_posted' => $posted, 'hold_reserved' => $reserved, 'matches' => $matches]);
            if (! $matches) {
                DB::table('wallet_balances')->where('wallet_id', $wallet)->update(['frozen' => true]);
                DB::table('financial_case_events')->insert(['id' => (string) Str::uuid(), 'run_id' => $id, 'actor_id' => $actor, 'kind' => 'opened', 'evidence' => 'Projection discrepancy: wallet frozen; no automatic correction.']);
            }
            Audit::record('finance.reconciled', $actor, $merchant, $id);

            return ['id' => $id, 'matches' => $matches, 'currency' => $w->currency, 'cutoff' => (string) $b->version];
        });
    }

    /** Transactional local consumer: delivery receipt is the idempotent, durable effect. */
    public function drain(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit) {
            $events = DB::table('financial_outbox as o')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('financial_outbox_deliveries as d')->whereColumn('d.event_id', 'o.id')->where('d.consumer', 'finance-monitor'))
                ->orderBy('o.created_at')->orderBy('o.id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($events as $event) {
                // No tenant container binding is established or inherited by this system-only consumer.
                DB::table('financial_outbox_deliveries')->insertOrIgnore(['event_id' => $event->id, 'consumer' => 'finance-monitor']);
            }

            return $events->count();
        });
    }
}
