<?php

namespace App\Modules\WalletBilling\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WalletRead
{
    public function wallets(string $merchant): array
    {
        $rows = DB::table('wallets as w')->join('wallet_balances as b', 'b.wallet_id', '=', 'w.id')->join('financial_currencies as c', 'c.code', '=', 'w.currency')
            ->where('w.merchant_id', $merchant)->orderBy('w.currency')->get(['w.id', 'w.currency', 'c.exponent', 'b.posted', 'b.reserved', 'b.version', 'b.frozen']);

        return ['wallets' => $rows->map(fn ($row) => $this->balance($row)), 'funding_enabled' => false, 'shipping_charges_enabled' => false];
    }

    private function balance(object $row): object
    {
        $row->posted = (string) $row->posted;
        $row->reserved = (string) $row->reserved;
        $row->available = (string) ((int) $row->posted - (int) $row->reserved);
        $row->version = (string) $row->version;

        return $row;
    }

    public function wallet(string $merchant, string $wallet): object
    {
        $rows = $this->wallets($merchant)['wallets'];
        $row = $rows->firstWhere('id', $wallet);
        abort_unless($row, 404);

        return $row;
    }

    public function history(Request $r, string $merchant, string $wallet, string $type, ?string $id = null): array
    {
        $balance = $this->wallet($merchant, $wallet);
        $d = $r->validate(['after' => 'sometimes|integer|min:0', 'cutoff' => 'sometimes|integer|min:0']);
        $cutoff = (int) ($d['cutoff'] ?? $balance->version);
        abort_if($cutoff > (int) $balance->version || (int) ($d['after'] ?? 0) > $cutoff, 422);
        if ($type === 'holds') {
            $q = DB::table('balance_holds')->where('merchant_id', $merchant)->where('wallet_id', $wallet);
            if ($id) {
                $hold = $q->where('id', $id)->first();
                abort_unless($hold, 404);
                foreach (['original', 'captured', 'released', 'version'] as $field) {
                    $hold->$field = (string) $hold->$field;
                }
                $hold->remaining = (string) ((int) $hold->original - (int) $hold->captured - (int) $hold->released);
                $hold->events = DB::table('balance_hold_events')->where('merchant_id', $merchant)->where('hold_id', $id)->orderBy('created_at')->orderBy('id')->limit(100)->get()->map(function ($e) {
                    $e->amount = (string) $e->amount;

                    return $e;
                });

                return ['hold' => $hold];
            }
            $r->validate(['cursor' => 'sometimes|uuid']);
            if ($r->filled('cursor')) {
                $q->where('id', '>', $r->input('cursor'));
            }
            $rows = $q->orderBy('id')->limit(26)->get()->map(function ($h) {
                foreach (['original', 'captured', 'released', 'version'] as $field) {
                    $h->$field = (string) $h->$field;
                }

                return $h;
            });

            return ['holds' => $rows->take(25)->values(), 'next_cursor' => $rows->count() > 25 ? $rows[24]->id : null];
        }
        $q = DB::table('wallet_transactions')->where('merchant_id', $merchant)->where('wallet_id', $wallet)->where('sequence', '<=', $cutoff);
        if ($id) {
            $q->where('id', $id);
        } else {
            $q->where('sequence', '>', $d['after'] ?? 0);
        }
        $rows = $q->orderBy('sequence')->limit(26)->get()->map(function ($row) {
            foreach (['sequence', 'delta', 'posted_after', 'reserved_after'] as $field) {
                $row->$field = (string) $row->$field;
            }
            $row->available_after = (string) ((int) $row->posted_after - (int) $row->reserved_after);

            return $row;
        });
        abort_if($id && $rows->isEmpty(), 404);
        $opening = DB::table('wallet_transactions')->where('wallet_id', $wallet)->where('sequence', '<=', $d['after'] ?? 0)->orderByDesc('sequence')->value('posted_after') ?? 0;
        $closing = DB::table('wallet_transactions')->where('wallet_id', $wallet)->where('sequence', '<=', $cutoff)->orderByDesc('sequence')->value('posted_after') ?? 0;

        return ['wallet' => $balance, 'transactions' => $rows->take(25)->values(), 'next_after' => $rows->count() > 25 ? $rows[24]->sequence : null, 'cutoff' => (string) $cutoff, 'opening' => (string) $opening, 'closing' => (string) $closing];
    }
}
