<?php

namespace App\Modules\Pricing\Http;

use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Application\Quotes;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class PricingController
{
    public function rates(Request $r, MerchantContext $c, Catalog $catalog): mixed
    {
        $c->require('rates.read');

        // Discovery is configuration only, never a claim of live route availability.
        return ApiResponse::success(['services' => array_values(array_map(fn ($s) => ['id' => $s['id'], 'carrier' => $s['config']['carrier'], 'name_en' => $s['config']['name_en'], 'name_ar' => $s['config']['name_ar'], 'availability' => 'not_live_verified', 'shipping_enabled' => false], array_filter($catalog->active(), fn ($s) => $s['kind'] === 'service')))]);
    }

    public function quote(Request $r, MerchantContext $c, Quotes $quotes, ?string $quote = null): mixed
    {
        return ApiResponse::success($quotes->create($r, $c, $quote), 201);
    }

    public function history(Request $r, MerchantContext $c): mixed
    {
        $c->require('rates.read');
        $d = $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $p = DB::table('shipping_quotes')->where('merchant_id', $c->id())->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['public_snapshot'], 'page', $d['page'] ?? 1);

        return ApiResponse::success(['quotes' => array_map(fn ($q) => json_decode($q->public_snapshot, true), $p->items()), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    public function show(Request $r, MerchantContext $c, Quotes $quotes, string $quote, ?string $action = null): mixed
    {
        $c->require('rates.read');

        return ApiResponse::success($action === 'validate' ? $quotes->valid($c->id(), $quote) : $quotes->show($c->id(), $quote));
    }

    public function admin(Request $r, Catalog $catalog, ?string $merchant = null): mixed
    {
        if ($r->isMethod('post')) {
            return ApiResponse::success($catalog->write($r, $merchant), 201);
        }

        return ApiResponse::success(DB::transaction(function () use ($r, $catalog, $merchant) {
            PlatformSecurity::lock();
            $catalog->authorize($r, 'platform.pricing.view', $merchant);

            return ['definitions' => $catalog->rows($merchant)];
        }));
    }

    public function merchantUpdate(Request $r, Catalog $catalog, string $merchant, string $definition): mixed
    {
        return $this->update($r, $catalog, $definition, $merchant);
    }

    public function merchantChange(Request $r, Catalog $catalog, string $merchant, string $definition, string $action): mixed
    {
        return $this->change($r, $catalog, $definition, $action, $merchant);
    }

    public function update(Request $r, Catalog $catalog, string $definition, ?string $merchant = null): mixed
    {
        return ApiResponse::success($catalog->write($r, $merchant, $definition));
    }

    public function change(Request $r, Catalog $catalog, string $definition, string $action, ?string $merchant = null): mixed
    {
        return ApiResponse::success($catalog->change($r, $definition, $action, $merchant));
    }

    public function audit(Request $r, Catalog $catalog, ?string $merchant = null): mixed
    {
        return ApiResponse::success(DB::transaction(function () use ($r, $catalog, $merchant) {
            PlatformSecurity::lock();
            $catalog->authorize($r, 'platform.pricing.view', $merchant);

            return ['events' => DB::table('pricing_events as e')->join('pricing_definitions as d', 'd.id', '=', 'e.definition_id')->where('d.merchant_id', $merchant)->orderByDesc('e.created_at')->limit(100)->get(['e.*'])];
        }));
    }

    public function versions(Request $r, Catalog $catalog, string $definition): mixed
    {
        return $this->merchantVersions($r, $catalog, null, $definition);
    }

    public function merchantVersions(Request $r, Catalog $catalog, ?string $merchant, string $definition): mixed
    {
        return ApiResponse::success(DB::transaction(function () use ($r, $catalog, $merchant, $definition) {
            PlatformSecurity::lock();
            $catalog->authorize($r, 'platform.pricing.view', $merchant);
            abort_unless(DB::table('pricing_definitions')->where('id', $definition)->where('merchant_id', $merchant)->exists(), 404);

            return ['versions' => DB::table('pricing_versions')->where('definition_id', $definition)->orderByDesc('version')->limit(100)->get(['version', 'configuration', 'currency', 'effective_from', 'effective_to', 'created_by', 'created_at'])->map(function ($v) {
                $v->configuration = json_decode($v->configuration, true);

                return $v;
            })];
        }));
    }

    public function privateQuote(Request $r, Catalog $catalog, Quotes $quotes, string $merchant, string $quote): mixed
    {
        return ApiResponse::success(DB::transaction(function () use ($r, $catalog, $quotes, $merchant, $quote) {
            PlatformSecurity::lock();
            $catalog->authorize($r, 'platform.pricing.view', $merchant);

            return ['quote' => $quotes->show($merchant, $quote), 'internal' => json_decode(Crypt::decryptString($quotes->row($merchant, $quote)->private_snapshot), true)];
        }));
    }
}
