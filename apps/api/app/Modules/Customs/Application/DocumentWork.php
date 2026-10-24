<?php

namespace App\Modules\Customs\Application;

use App\Modules\Customs\Infrastructure\DraftPdf;
use App\Modules\Customs\Jobs\GenerateDocument;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\DocumentScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DocumentWork
{
    private function enabled(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 503);
    }

    public function request(Request $r, MerchantContext $c, string $shipment): array
    {
        $this->enabled();
        $d = $r->validate(['version' => 'required|integer|min:1', 'declaration_version' => 'required|integer|min:1', 'kind' => 'required|in:invoice,packing_list', 'locale' => 'required|in:en,ar']);

        return app(Declarations::class)->command($r, $c, 'customs.documents', 'document:'.$shipment, $d, function () use ($r, $c, $shipment, $d) {
            $c->require('shipments.documents');
            $s = app(Shipments::class)->row($c->id(), $shipment);
            abort_unless($s->version === $d['version'], 409);
            $decl = DB::table('customs_declarations')->where('merchant_id', $c->id())->where('shipment_id', $shipment)->first();
            abort_unless($decl && $decl->version === $d['declaration_version'], 409);
            $v = DB::table('customs_versions')->where('merchant_id', $c->id())->where('declaration_id', $decl->id)->where('version', $decl->version)->where('revision', $s->revision)->first();
            abort_unless($v, 409);
            $draft = app(Shipments::class)->revision($s);
            abort_unless(app(Readiness::class)->report($draft)['local_valid'], 422);
            $old = DB::table('customs_documents')->where('merchant_id', $c->id())->where('declaration_id', $decl->id)->where('version', $decl->version)->where('kind', $d['kind'])->where('locale', $d['locale'])->first();
            if ($old) {
                return ['document_id' => $old->id];
            }
            DB::table('customs_document_counters')->insertOrIgnore(['merchant_id' => $c->id(), 'kind' => $d['kind'], 'number' => 1]);
            $counter = DB::table('customs_document_counters')->where('merchant_id', $c->id())->where('kind', $d['kind'])->lockForUpdate()->first();
            $number = 'DRAFT-'.($d['kind'] === 'invoice' ? 'CI' : 'PL').'-'.str_pad((string) $counter->number, 8, '0', STR_PAD_LEFT);
            DB::table('customs_document_counters')->where('merchant_id', $c->id())->where('kind', $d['kind'])->increment('number');
            $id = (string) Str::uuid();
            $customs = json_decode(Crypt::decryptString($v->payload_ciphertext), true);
            DB::table('customs_documents')->insert(['id' => $id, 'merchant_id' => $c->id(), 'declaration_id' => $decl->id, 'version' => $decl->version, 'shipment_id' => $shipment, 'revision' => $s->revision, 'kind' => $d['kind'], 'locale' => $d['locale'], 'number' => $number, 'invoice_date' => $customs['invoice_date']]);
            $work = (string) Str::uuid();
            DB::table('customs_document_work')->insert(['id' => $work, 'merchant_id' => $c->id(), 'document_id' => $id, 'state' => 'pending'] + app(Declarations::class)->origin($r));
            app(Declarations::class)->event($c->id(), $shipment, 'document_requested', $r->user()->id);
            DB::afterCommit(fn () => GenerateDocument::dispatch($c->id(), $work));

            return ['document_id' => $id, 'work_id' => $work, 'state' => 'pending'];
        });
    }

    private function authority(object $w, object $doc): array
    {
        $this->enabled();
        app(ShippingAccess::class)->worker($w, 'customs.documents');
        app(ShippingAccess::class)->worker($w, 'shipments.documents');
        abort_if(DB::table('customs_document_revocations')->where('document_id', $doc->id)->exists(), 403);
        $s = app(Shipments::class)->row($w->merchant_id, $doc->shipment_id);
        $decl = DB::table('customs_declarations')->where('merchant_id', $w->merchant_id)->where('id', $doc->declaration_id)->first();
        abort_unless($s->revision === $doc->revision && $decl->version === $doc->version, 409);
        $draft = app(Shipments::class)->revision($s);
        abort_unless(app(Readiness::class)->report($draft)['local_valid'], 409);

        return $draft;
    }

    public function tick(string $merchant, string $id): void
    {
        $token = (string) Str::uuid();
        $w = DB::transaction(function () use ($merchant, $id, $token) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $w = DB::table('customs_document_work')->where('merchant_id', $merchant)->where('id', $id)->lockForUpdate()->first();
            if (! $w || ! in_array($w->state, ['pending', 'processing']) || ($w->lease_until && now()->lt($w->lease_until))) {
                return null;
            }if ($w->attempts >= 5) {
                DB::table('customs_document_work')->where('id', $id)->update(['state' => 'failed', 'error_code' => 'ATTEMPTS_EXHAUSTED']);

                return null;
            }$doc = DB::table('customs_documents')->where('merchant_id', $merchant)->where('id', $w->document_id)->first();
            try {
                $this->authority($w, $doc);
            } catch (HttpExceptionInterface) {
                DB::table('customs_document_work')->where('id', $id)->update(['state' => 'blocked', 'error_code' => 'AUTHORITY_OR_REVISION_CHANGED']);

                return null;
            }DB::table('customs_document_work')->where('id', $id)->update(['state' => 'processing', 'attempts' => $w->attempts + 1, 'lease_token' => $token, 'lease_until' => now()->addSeconds(90)]);

            return DB::table('customs_document_work')->where('id', $id)->first();
        });
        if (! $w) {
            return;
        }
        $key = 'customs-documents/'.$merchant.'/'.$w->document_id.'.enc';
        try {
            $doc = DB::table('customs_documents')->where('merchant_id', $merchant)->where('id', $w->document_id)->first();
            $s = app(Shipments::class)->row($merchant, $doc->shipment_id);
            $draft = app(Shipments::class)->revision($s);
            $c = json_decode(Crypt::decryptString(DB::table('customs_versions')->where('merchant_id', $merchant)->where('declaration_id', $doc->declaration_id)->where('version', $doc->version)->value('payload_ciphertext')), true);
            $bytes = app(DraftPdf::class)->render($doc, $c, $draft);
            abort_unless(strlen($bytes) <= 5242880 && str_starts_with($bytes, '%PDF-'), 422);
            $clean = app(DocumentScanner::class)->clean($bytes);
            $checksum = hash('sha256', $bytes);
            // Commit authority, private bytes and result under the same merchant fence; an orphan from a crash is overwritten by the same document identity.
            DB::transaction(function () use ($merchant, $id, $token, $w, $doc, $key, $bytes, $checksum, $clean) {
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                $current = DB::table('customs_document_work')->where('id', $id)->lockForUpdate()->first();
                if ($current->state !== 'processing' || $current->lease_token !== $token || now()->gte($current->lease_until)) {
                    return;
                }$this->authority($current, $doc);
                abort_unless(Storage::disk('local')->put($key, Crypt::encryptString($bytes)), 503);
                DB::table('customs_document_results')->insert(['document_id' => $doc->id, 'merchant_id' => $merchant, 'object_key' => $key, 'checksum' => $checksum, 'size_bytes' => strlen($bytes), 'state' => $clean ? 'ready' : 'quarantined']);
                DB::table('customs_document_work')->where('id', $id)->update(['state' => 'completed', 'lease_until' => null]);
                app(Declarations::class)->event($merchant, $doc->shipment_id, 'document_generated', $w->actor_id);
            });
        } catch (\Throwable $e) {
            $terminal = $e instanceof HttpExceptionInterface || $e instanceof ValidationException;
            DB::transaction(function () use ($merchant, $id, $token, $terminal) {
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                $w = DB::table('customs_document_work')->where('id', $id)->lockForUpdate()->first();
                if ($w->state === 'processing' && $w->lease_token === $token) {
                    DB::table('customs_document_work')->where('id', $id)->update(['state' => $terminal ? 'blocked' : ($w->attempts >= 5 ? 'failed' : 'pending'), 'error_code' => $terminal ? 'AUTHORITY_REVISION_OR_POLICY_CHANGED' : 'GENERATION_RETRYABLE', 'lease_until' => null]);
                }
            });
        }
    }

    public function download(Request $r, MerchantContext $c, string $id): mixed
    {
        $this->enabled();

        return DB::transaction(function () use ($r, $c, $id) {
            app(ShippingAccess::class)->lock($r, $c, 'customs.documents');
            $c->require('shipments.documents');
            $doc = DB::table('customs_documents')->where('merchant_id', $c->id())->where('id', $id)->first();
            abort_unless($doc, 404);
            abort_if(DB::table('customs_document_revocations')->where('merchant_id', $c->id())->where('document_id', $id)->exists(), 409);
            $result = DB::table('customs_document_results')->where('merchant_id', $c->id())->where('document_id', $id)->where('state', 'ready')->first();
            abort_unless($result, 409);
            try {
                $bytes = Crypt::decryptString(Storage::disk('local')->get($result->object_key));
            } catch (\Throwable) {
                abort(503);
            }abort_unless(hash_equals($result->checksum, hash('sha256', $bytes)), 503);
            Audit::record('customs.document_downloaded', $r->user()->id, $c->id(), $id);
            $s = app(Shipments::class)->row($c->id(), $doc->shipment_id);

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$doc->number.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'X-Document-Draft' => 'true', 'X-Document-Historical' => $s->revision === $doc->revision ? 'false' : 'true']);
        });
    }

    public function recover(): int
    {
        $n = 0;
        DB::table('customs_document_work')->whereIn('state', ['pending', 'processing'])->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->orderBy('id')->chunkById(100, function ($rows) use (&$n) {
            foreach ($rows as $w) {
                GenerateDocument::dispatch($w->merchant_id, $w->id);
                $n++;
            }
        });

        return $n;
    }
}
