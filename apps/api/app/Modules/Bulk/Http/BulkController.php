<?php

namespace App\Modules\Bulk\Http;

use App\Modules\Bulk\Application\Imports;
use App\Modules\Bulk\Domain\Columns;
use App\Modules\Bulk\Infrastructure\Templates;
use App\Modules\Customs\Application\Readiness;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\AvailableServices;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class BulkController
{
    public function index(Request $r, MerchantContext $c): mixed
    {
        $c->require('bulk.read');
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $p = DB::table('bulk_batches')->where('merchant_id', $c->id())->orderByDesc('created_at')->orderByDesc('id')->paginate(25, ['id', 'state', 'version', 'format', 'row_count', 'created_at']);

        return ApiResponse::success(['batches' => $p->items(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    public function show(Request $r, MerchantContext $c, string $batch): mixed
    {
        $c->require('bulk.read');

        return ApiResponse::success(app(Imports::class)->show($c->id(), $batch));
    }

    public function initialize(Request $r, MerchantContext $c): mixed
    {
        $m = $c->id();
        $id = app(Imports::class)->initialize($r, $c);

        return ApiResponse::success(app(Imports::class)->show($m, $id), 201);
    }

    public function command(Request $r, MerchantContext $c, string $batch, ?string $row = null, ?string $action = null): mixed
    {
        $m = $c->id();
        $s = app(Imports::class);
        $action = $r->route('action');
        $row = $r->route('row');
        match ($action) {
            'file' => $s->upload($r, $c, $batch),'mapping' => $s->mapping($r, $c, $batch),'decision' => $s->decision($r, $c, $batch, $row),'pricing' => $s->start($r, $c, $batch, 'pricing'),'prepare' => $s->start($r, $c, $batch, 'preparing'),'cancel' => $s->cancel($r, $c, $batch),default => abort(404)
        };

        return ApiResponse::success($s->show($m, $batch));
    }

    public function rows(Request $r, MerchantContext $c, string $batch): mixed
    {
        $c->require('bulk.read');
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $b = app(Imports::class)->batch($c->id(), $batch);
        abort_if(in_array($b->state, ['initialized', 'parsing', 'failed']), 409);
        $p = DB::table('bulk_rows')->where('merchant_id', $c->id())->where('batch_id', $batch)->orderBy('source_row')->paginate(50);
        $items = [];
        $ids = collect($p->items())->pluck('id')->all();
        $valid = DB::table('bulk_validations')->where('merchant_id', $c->id())->where('batch_id', $batch)->where('mapping_version', $b->mapping_version)->whereIn('row_id', $ids)->get()->keyBy('row_id');
        $decisions = DB::table('bulk_decisions')->where('merchant_id', $c->id())->where('batch_id', $batch)->where('mapping_version', $b->mapping_version)->whereIn('row_id', $ids)->get()->keyBy('row_id');
        $prices = app(Imports::class)->latestPrices($b)->whereIn('row_id', $ids)->get()->keyBy('row_id');
        $drafts = DB::table('bulk_drafts')->where('merchant_id', $c->id())->where('batch_id', $batch)->whereIn('row_id', $ids)->get()->keyBy('row_id');
        foreach ($p->items() as $row) {
            $v = $valid[$row->id] ?? null;
            $price = $prices[$row->id] ?? null;
            $public = $price?->public_snapshot ? json_decode($price->public_snapshot, true) : null;
            $draft = $v?->draft ? json_decode(Crypt::decryptString($v->draft), true) : null;
            $customs = $draft && isset($draft['customs']) ? app(Readiness::class)->report($draft) : null;
            $items[] = ['customs_readiness' => $customs, 'id' => $row->id, 'source_row' => $row->source_row, 'source' => json_decode(Crypt::decryptString($row->raw), true), 'errors' => $v ? json_decode($v->errors, true) : null, 'duplicate_of' => $v?->duplicate_of, 'decision' => $decisions[$row->id]->decision ?? null, 'pricing' => $public, 'pricing_error' => $price?->error_code, 'quote_valid' => $public ? now()->lt($public['expires_at']) && app(AvailableServices::class)->contains($public['service_id']) : false, 'shipment_id' => $drafts[$row->id]->shipment_id ?? null];
        }

        return ApiResponse::success(['rows' => $items, 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    public function events(Request $r, MerchantContext $c, string $batch): mixed
    {
        $c->require('bulk.read');
        app(Imports::class)->batch($c->id(), $batch);
        $r->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $p = DB::table('bulk_events')->where('merchant_id', $c->id())->where('batch_id', $batch)->orderByDesc('created_at')->orderByDesc('id')->paginate(50, ['id', 'kind', 'metadata', 'created_at']);

        return ApiResponse::success(['events' => $p->items(), 'page' => $p->currentPage(), 'last_page' => $p->lastPage()]);
    }

    public function errors(Request $r, MerchantContext $c, string $batch): mixed
    {
        $c->require('bulk.export');
        $b = app(Imports::class)->batch($c->id(), $batch);
        abort_unless(in_array($b->state, ['review', 'completed', 'partial', 'preparing', 'pricing', 'blocked']), 409);
        $locale = $r->validate(['locale' => 'sometimes|in:en,ar'])['locale'] ?? 'en';
        $headers = json_decode(Crypt::decryptString($b->headers), true);
        Audit::record('bulk.errors_downloaded', $c->actorId(), $c->id(), $batch);

        return response()->streamDownload(function () use ($b, $locale, $headers) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$locale === 'ar' ? 'رقم الصف' : 'Source row', $locale === 'ar' ? 'حقول تحتاج مراجعة' : 'Fields requiring attention', ...array_map([Columns::class, 'safeExport'], $headers)], ',', '"', '');
            $q = DB::table('bulk_rows as r')->join('bulk_validations as v', 'v.row_id', '=', 'r.id')->where('r.merchant_id', $b->merchant_id)->where('v.merchant_id', $b->merchant_id)->where('r.batch_id', $b->id)->where('v.batch_id', $b->id)->where('v.mapping_version', $b->mapping_version)->whereRaw('jsonb_array_length(v.errors)>0')->orderBy('r.source_row')->select('r.*', 'v.errors');
            foreach ($q->cursor() as $row) {
                fputcsv($out, [$row->source_row, implode(' | ', array_map(fn ($key) => Columns::errorLabel($key, $locale), json_decode($row->errors, true))), ...array_map([Columns::class, 'safeExport'], json_decode(Crypt::decryptString($row->raw), true))], ',', '"', '');
            }fclose($out);
        }, 'import-'.$batch.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox']);
    }

    public function template(Request $r, MerchantContext $c, string $locale, string $format): mixed
    {
        $c->require('bulk.read');
        abort_unless(in_array($locale, ['en', 'ar']) && in_array($format, ['csv', 'xlsx']), 404);

        $d = $r->validate(['variant' => 'sometimes|in:standard,international']);

        return app(Templates::class)->download($locale, $format, $d['variant'] ?? 'standard');
    }
}
