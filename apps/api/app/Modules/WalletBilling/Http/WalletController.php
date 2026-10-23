<?php

namespace App\Modules\WalletBilling\Http;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\WalletBilling\Application\Ledger;
use App\Modules\WalletBilling\Application\WalletRead;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WalletController
{
    public function index(Request $r, MerchantContext $c, WalletRead $read, Ledger $ledger): mixed
    {
        $c->require($r->isMethod('post') ? 'wallet.open' : 'wallet.read');
        if ($r->isMethod('post')) {
            $r->validate(['currency' => 'required|in:SAR', 'merchant_id' => 'prohibited', 'amount' => 'prohibited']);
            DB::transaction(function () use ($r, $c, $ledger) {
                DB::table('merchants')->where('id', $c->id())->lockForUpdate()->first();
                $user = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
                app(Sessions::class)->validate($r, $user);
                $c->establish($user, $c->id(), $r->user()->currentAccessToken() instanceof PersonalAccessToken ? $r->user()->currentAccessToken() : null);
                $c->require('wallet.open');
                $ledger->provision($c->id());
            });
        }

        return ApiResponse::success($read->wallets($c->id()));
    }

    public function show(Request $r, MerchantContext $c, WalletRead $read, string $wallet, ?string $kind = null, ?string $entry = null): mixed
    {
        abort_if($kind === 'statement' && $entry !== null, 404);
        $c->require(match ($kind) {
            'holds' => 'wallet.holds.read', 'statement' => 'wallet.statements.read', 'transactions' => 'wallet.transactions.read', default => 'wallet.read'
        });

        return ApiResponse::success($kind ? $read->history($r, $c->id(), $wallet, $kind, $entry) : ['wallet' => $read->wallet($c->id(), $wallet)]);
    }
}
