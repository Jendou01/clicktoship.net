<?php

namespace App\Modules\Shipping\Application;

use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Domain\DocumentScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class Documents
{
    private function testOnly(): void
    {
        abort_unless(app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e']), 503);
    }

    /** Internal ingestion only. No public upload, arbitrary URL fetch or real carrier label endpoint. */
    public function quarantine(string $merchant, string $shipment, string $pdf): string
    {
        $this->testOnly();
        app(Shipments::class)->row($merchant, $shipment);
        abort_unless(strlen($pdf) > 8 && strlen($pdf) <= 5242880 && str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -1024), '%%EOF'), 422);
        $id = (string) Str::uuid();
        $key = 'shipment-documents/'.$merchant.'/'.$id.'.pdf';
        abort_unless(Storage::disk('local')->put($key, $pdf), 503);
        try {
            DB::transaction(function () use ($id, $merchant, $shipment, $key, $pdf) {
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                DB::table('shipment_documents')->insert(['id' => $id, 'merchant_id' => $merchant, 'shipment_id' => $shipment, 'object_key' => $key, 'checksum' => hash('sha256', $pdf), 'size_bytes' => strlen($pdf), 'mime' => 'application/pdf', 'state' => 'quarantined', 'provenance' => 'synthetic_test_only']);
                DB::table('shipments')->where('merchant_id', $merchant)->where('id', $shipment)->update(['document_state' => 'quarantined']);
                app(Shipments::class)->event($merchant, $shipment, 'document_quarantined');
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($key);
            throw $e;
        }

        return $id;
    }

    public function scan(string $merchant, string $id): void
    {
        $this->testOnly();
        $d = $this->row($merchant, $id);
        abort_unless($d->state === 'quarantined', 409);
        $bytes = Storage::disk('local')->get($d->object_key);
        $clean = hash_equals($d->checksum, hash('sha256', $bytes)) && app(DocumentScanner::class)->clean($bytes);
        DB::transaction(function () use ($merchant, $id, $d, $clean) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $current = $this->row($merchant, $id);
            abort_unless($current->state === 'quarantined', 409);
            DB::table('shipment_documents')->where('id', $id)->update(['state' => $clean ? 'ready' : 'failed']);
            DB::table('shipments')->where('merchant_id', $merchant)->where('id', $d->shipment_id)->update(['document_state' => $clean ? 'ready' : 'failed']);
            app(Shipments::class)->event($merchant, $d->shipment_id, $clean ? 'document_validated' : 'document_failed');
        });
    }

    public function row(string $merchant, string $id): object
    {
        $d = DB::table('shipment_documents')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($d, 404);

        return $d;
    }

    public function download(Request $r, MerchantContext $c, string $id): mixed
    {
        return DB::transaction(function () use ($r, $c, $id) {
            app(ShippingAccess::class)->lock($r, $c, 'shipments.documents');
            $d = $this->row($c->id(), $id);
            $this->testOnly();
            abort_unless($d->state === 'ready', 409);
            $bytes = Storage::disk('local')->get($d->object_key);
            abort_unless(hash_equals($d->checksum, hash('sha256', $bytes)), 503);
            Audit::record('shipping.document_downloaded', $c->actorId(), $c->id(), $id);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="shipping-label.pdf"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
        });
    }

    public function revoke(Request $r, MerchantContext $c, string $id): array
    {
        return DB::transaction(function () use ($r, $c, $id) {
            app(ShippingAccess::class)->lock($r, $c, 'shipments.documents');
            $d = $this->row($c->id(), $id);
            DB::table('shipment_documents')->where('id', $id)->update(['state' => 'revoked']);
            DB::table('shipments')->where('merchant_id', $c->id())->where('id', $d->shipment_id)->update(['document_state' => 'not_requested']);
            app(Shipments::class)->event($c->id(), $d->shipment_id, 'document_revoked', $c->actorId());

            return ['revoked' => true];
        });
    }
}
