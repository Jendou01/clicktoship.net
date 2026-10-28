<?php

namespace App\Support\Security;

use App\Models\User;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\WalletBilling\Application\Ledger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Seed only drafts through the same permission/service boundary as HTTP. */
final class DemoFixtures
{
    public function create(string $owner, string $merchant): array
    {
        abort_unless(app()->environment('demo') && config('demo.enabled') === true && DB::connection()->getDatabaseName() === 'clicktoship_demo', 403);
        DisposableDemo::assertConfiguration();

        return DB::transaction(function () use ($owner, $merchant): array {
            $user = User::whereKey($owner)->lockForUpdate()->firstOrFail();
            abort_unless($user->hasVerifiedEmail() && str_ends_with($user->email, '@example.invalid'), 403);
            abort_unless(DB::table('merchant_role_assignments as a')
                ->join('merchant_users as m', 'm.id', '=', 'a.membership_id')
                ->join('roles as r', 'r.id', '=', 'a.role_id')
                ->where('a.merchant_id', $merchant)->where('m.merchant_id', $merchant)
                ->where('m.user_id', $owner)->where('m.status', 'active')
                ->where('r.code', 'merchant.owner')->exists(), 403);
            $token = $user->createToken('Disposable synthetic draft fixture', ['shipments.write'], now()->addMinutes(5));
            $token->accessToken->forceFill(['merchant_id' => $merchant, 'auth_version' => $user->auth_version])->save();
            $user->withAccessToken($token->accessToken);
            $context = app(MerchantContext::class);
            try {
                $context->establish($user, $merchant, $token->accessToken);
                $context->require('shipments.write');
                $ids = [];
                for ($i = 1; $i <= 3; $i++) {
                    $request = Request::create('/internal/demo-draft', 'POST', [
                        'idempotency_key' => 'disposable-demo-draft-'.$i,
                        'draft' => [
                            'currency' => 'SAR', 'description' => 'TEST DEMO synthetic goods '.$i,
                            'origin' => ['country' => 'SA', 'city' => 'synthetic origin'],
                            'destination' => ['country' => 'SA', 'city' => 'synthetic destination'],
                            'packages' => [['weight_grams' => 1000, 'length_mm' => 100, 'width_mm' => 100, 'height_mm' => 100]],
                            'declared_value_minor' => '0', 'cod_minor' => '0', 'items' => [],
                        ],
                    ]);
                    $request->setUserResolver(fn () => $user);
                    $shipment = app(Shipments::class)->write($request, $context)['shipment'];
                    $ids[] = is_array($shipment) ? $shipment['id'] : $shipment->id;
                }
                $wallet = app(Ledger::class)->provision($merchant);

                return ['shipment_ids' => $ids, 'wallet_id' => $wallet, 'status' => 'draft', 'financial_activity' => false];
            } finally {
                $context->clear();
                $token->accessToken->delete();
                $user->withAccessToken(null);
            }
        });
    }
}
