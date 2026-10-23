<?php

namespace App\Modules\WalletBilling\Http;

use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\WalletBilling\Application\FinancialAdministration;
use App\Modules\WalletBilling\Application\Reconciliation;
use App\Modules\WalletBilling\Application\WalletRead;
use App\Support\ApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class FinanceAdminController
{
    public function wallets(Request $r, FinancialAdministration $s, WalletRead $read, string $merchant): mixed
    {
        $s->authorize($r, $merchant, 'platform.wallet.view');

        return ApiResponse::success($read->wallets($merchant));
    }

    public function wallet(Request $r, FinancialAdministration $s, WalletRead $read, string $merchant, string $wallet, ?string $kind = null, ?string $entry = null): mixed
    {
        abort_if($kind === 'statement' && $entry !== null, 404);
        $s->authorize($r, $merchant, 'platform.wallet.view');

        return ApiResponse::success($kind ? $read->history($r, $merchant, $wallet, $kind, $entry) : ['wallet' => $read->wallet($merchant, $wallet)]);
    }

    public function requests(Request $r, FinancialAdministration $s, string $merchant): mixed
    {
        if ($r->isMethod('post')) {
            return ApiResponse::success($s->request($r, $merchant), 201);
        }
        $s->authorize($r, $merchant, 'platform.finance.correct');
        $r->validate(['cursor' => 'sometimes|uuid']);
        $q = DB::table('financial_approvals')->where('merchant_id', $merchant);
        if ($r->filled('cursor')) {
            $q->where('id', '>', $r->input('cursor'));
        }
        $rows = $q->orderBy('id')->limit(26)->get()->map(function ($row) {
            $row->payload = json_decode($row->payload, true);
            unset($row->payload['access_grant'],$row->payload['reason_digest']);
            $row->reason = Crypt::decryptString($row->reason);

            return $row;
        });

        return ApiResponse::success(['requests' => $rows->take(25)->values(), 'next_cursor' => $rows->count() > 25 ? $rows[24]->id : null, 'execution_enabled' => (bool) config('finance.corrections_enabled')]);
    }

    public function change(Request $r, FinancialAdministration $s, string $merchant, string $approval, string $action): mixed
    {
        try {
            $data = $s->change($r, $merchant, $approval, $action);
        } catch (QueryException $e) {
            if ($e->getCode() === '23514') {
                abort(409);
            } throw $e;
        }

        return ApiResponse::success($data);
    }

    public function reconcile(Request $r, FinancialAdministration $s, Reconciliation $reconciliation, string $merchant): mixed
    {
        if ($r->isMethod('post')) {
            $d = $r->validate(['wallet_id' => 'required|uuid']);
            $data = DB::transaction(function () use ($r, $s, $reconciliation, $merchant, $d) {
                PlatformSecurity::lock();
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                $s->lockAuthorities($r, $merchant);
                $s->authorize($r, $merchant, 'platform.finance.reconcile');

                return $reconciliation->run($merchant, $d['wallet_id'], $r->user()->id);
            });

            return ApiResponse::success($data, 201);
        }
        $s->authorize($r, $merchant, 'platform.finance.reconcile');
        $r->validate(['cursor' => 'sometimes|uuid']);
        $q = DB::table('financial_reconciliation_runs')->where('merchant_id', $merchant);
        if ($r->filled('cursor')) {
            $q->where('id', '>', $r->input('cursor'));
        }
        $rows = $q->orderBy('id')->limit(26)->get()->map(function ($row) {
            foreach (['posted', 'reserved', 'ledger_posted', 'hold_reserved', 'cutoff'] as $field) {
                $row->$field = (string) $row->$field;
            }

            return $row;
        });

        return ApiResponse::success(['runs' => $rows->take(25)->values(), 'next_cursor' => $rows->count() > 25 ? $rows[24]->id : null]);
    }
}
