<?php

namespace App\Modules\Bulk\Application;

use App\Modules\Bulk\Domain\Columns;
use App\Modules\Bulk\Domain\ImportFailure;
use App\Modules\Bulk\Infrastructure\PrivateFiles;
use App\Modules\Bulk\Infrastructure\SpreadsheetReader;
use App\Modules\Bulk\Jobs\ProcessImport;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Pricing\Application\ImportEstimates;
use App\Modules\Shipping\Application\DraftReadiness;
use App\Modules\Shipping\Application\Shipments;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Processor
{
    private function locked(string $merchant, string $work, ?string $lease, callable $fn): mixed
    {
        return DB::transaction(function () use ($merchant, $work, $lease, $fn) {
            // Match Phase 5 lock ordering: catalog before merchant. No network calls under these locks.
            Catalog::lock(true);
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $w = DB::table('bulk_work')->where('merchant_id', $merchant)->where('id', $work)->lockForUpdate()->first();
            if (! $w || $w->completed_at || ($lease !== null && $w->lease_token !== $lease)) {
                return null;
            }
            $b = app(Imports::class)->batch($merchant, $w->batch_id);
            if ($b->state !== $w->phase || $b->mapping_version !== $w->mapping_version) {
                return null;
            }
            app(ShippingAccess::class)->worker($b, match ($w->phase) {
                'parsing' => 'bulk.upload','validating' => 'bulk.map','pricing' => 'bulk.price','preparing' => 'bulk.prepare'
            });
            if ($w->phase === 'pricing') {
                app(ShippingAccess::class)->worker($b, 'rates.quote');
            }
            if ($w->phase === 'preparing') {
                app(ShippingAccess::class)->worker($b, 'shipments.write');
            }

            return $fn($b, $w);
        });
    }

    public function tick(string $merchant, string $id): bool
    {
        app(MerchantContext::class)->clear();
        $lease = (string) Str::uuid();
        try {
            $work = $this->locked($merchant, $id, null, function ($b, $w) use ($lease) {
                if ($w->lease_until && now()->lt($w->lease_until)) {
                    return null;
                }if ($w->lease_token && $w->lease_until && now()->gte($w->lease_until)) {
                    $retries = DB::table('bulk_events')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('kind', 'recovery_retry')->where('metadata->work_id', $w->id)->count();
                    if ($retries >= 5) {
                        throw new ImportFailure('RECOVERY_LIMIT');
                    }
                    app(Imports::class)->event($b, 'recovery_retry', ['work_id' => $w->id, 'cursor' => $w->cursor]);
                }
                DB::table('bulk_work')->where('id', $w->id)->update(['lease_token' => $lease, 'lease_until' => now()->addSeconds(90)]);

                return $w;
            });
            if (! $work) {
                return false;
            }
            if ($work->phase === 'parsing') {
                $this->parse($merchant, $id, $lease, $work->batch_id);
            } else {
                $this->chunk($merchant, $id, $lease);
            }

            return DB::table('bulk_work')->where('merchant_id', $merchant)->where('id', $id)->whereNull('completed_at')->exists();
        } catch (ImportFailure $e) {
            $this->stop($merchant, $id, $lease, 'failed', $e->getMessage());

            return false;
        } catch (HttpException $e) {
            $this->stop($merchant, $id, $lease, 'blocked', 'AUTHORIZATION_OR_STATE_CHANGED');

            return false;
        } finally {
            app(MerchantContext::class)->clear();
        }
    }

    private function stop(string $merchant, string $id, string $lease, string $state, string $code): void
    {
        // Recording a fail-closed result must not require the revoked actor's credentials.
        DB::transaction(function () use ($merchant, $id, $lease, $state, $code) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first();
            $w = DB::table('bulk_work')->where('merchant_id', $merchant)->where('id', $id)->whereNull('completed_at')->where(fn ($q) => $q->where('lease_token', $lease)->orWhereNull('lease_token')->orWhere('lease_until', '<', now()))->lockForUpdate()->first();
            if (! $w) {
                return;
            }$b = app(Imports::class)->batch($merchant, $w->batch_id);
            if ($b->state !== $w->phase) {
                return;
            }DB::table('bulk_batches')->where('id', $b->id)->update(['state' => $state, 'failure_code' => $code, 'version' => $b->version + 1, 'updated_at' => now()]);
            DB::table('bulk_work')->where('id', $id)->update(['completed_at' => now(), 'lease_until' => null]);
            app(Imports::class)->event($b, $state, ['code' => $code]);
        });
    }

    private function parse(string $merchant, string $id, string $lease, string $batch): void
    {
        $b = app(Imports::class)->batch($merchant, $batch);
        $file = DB::table('bulk_files')->where('merchant_id', $merchant)->where('batch_id', $batch)->first();
        if (! $file) {
            throw new ImportFailure('FILE_MISSING');
        }
        app(PrivateFiles::class)->withPlain($file, function ($path) use ($merchant, $id, $lease, $b) {
            $headers = null;
            $chunk = [];
            $last = 1;
            $count = 0;
            foreach (app(SpreadsheetReader::class)->rows($path, $b->format, $b->delimiter) as $number => $cells) {
                if ($headers === null) {
                    if ($number !== 1 || ! $cells) {
                        throw new ImportFailure('HEADER_ROW');
                    }$headers = $cells;
                    $ok = $this->locked($merchant, $id, $lease, function ($batch) use ($cells) {
                        DB::table('bulk_batches')->where('id', $batch->id)->update(['headers' => Crypt::encryptString(json_encode($cells))]);

                        return true;
                    });
                    if (! $ok) {
                        return;
                    }

                    continue;
                }
                if (count($cells) > count($headers)) {
                    throw new ImportFailure('ROW_COLUMN_MISMATCH');
                }
                $chunk[] = ['number' => $number, 'cells' => array_pad($cells, count($headers), '')];
                $last = $number;
                $count++;
                if (count($chunk) >= config('bulk.chunk_rows')) {
                    if (! $this->stage($merchant, $id, $lease, $chunk, $count)) {
                        return;
                    }$chunk = [];
                }
            }
            if ($chunk && ! $this->stage($merchant, $id, $lease, $chunk, $count)) {
                return;
            }
            $this->locked($merchant, $id, $lease, function ($batch, $w) use ($count) {
                DB::table('bulk_batches')->where('id', $batch->id)->update(['state' => 'mapping', 'row_count' => $count, 'version' => $batch->version + 1, 'updated_at' => now()]);
                DB::table('bulk_work')->where('id', $w->id)->update(['completed_at' => now(), 'lease_until' => null]);
                app(Imports::class)->event($batch, 'parsed', ['rows' => $count]);
            });
        });
    }

    private function stage(string $merchant, string $id, string $lease, array $rows, int $count): bool
    {
        return (bool) $this->locked($merchant, $id, $lease, function ($b, $w) use ($rows, $count) {
            foreach ($rows as $row) {
                $hash = hash_hmac('sha256', json_encode($row['cells']), config('app.key'));
                DB::table('bulk_rows')->insertOrIgnore(['id' => (string) Str::uuid(), 'merchant_id' => $b->merchant_id, 'batch_id' => $b->id, 'source_row' => $row['number'], 'raw' => Crypt::encryptString(json_encode($row['cells'])), 'raw_hash' => $hash]);
                $saved = DB::table('bulk_rows')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('source_row', $row['number'])->value('raw_hash');
                if (! $saved || ! hash_equals($saved, $hash)) {
                    throw new ImportFailure('ROW_TAMPERED');
                }
            }DB::table('bulk_work')->where('id', $w->id)->update(['cursor' => end($rows)['number'], 'lease_until' => now()->addSeconds(90)]);
            DB::table('bulk_batches')->where('id', $b->id)->update(['row_count' => $count]);

            return true;
        });
    }

    private function chunk(string $merchant, string $id, string $lease): void
    {
        $w = DB::table('bulk_work')->where('merchant_id', $merchant)->where('id', $id)->first();
        $b = app(Imports::class)->batch($merchant, $w->batch_id);
        $rows = DB::table('bulk_rows')->where('merchant_id', $merchant)->where('batch_id', $b->id)->where('source_row', '>', $w->cursor)->orderBy('source_row')->limit(config('bulk.chunk_rows'))->get();
        foreach ($rows as $row) {
            $ok = $this->locked($merchant, $id, $lease, function ($batch, $work) use ($row) {
                if ($row->source_row <= $work->cursor) {
                    return true;
                }
                if ($work->phase === 'validating') {
                    $this->validateRow($batch, $row);
                } elseif ($work->phase === 'pricing') {
                    $this->priceRow($batch, $work, $row);
                } else {
                    $this->prepareRow($batch, $row);
                }
                DB::table('bulk_work')->where('id', $work->id)->update(['cursor' => $row->source_row, 'lease_until' => now()->addSeconds(90)]);

                return true;
            });
            if (! $ok) {
                return;
            }
        }
        $this->locked($merchant, $id, $lease, function ($batch, $work) {
            $more = DB::table('bulk_rows')->where('merchant_id', $batch->merchant_id)->where('batch_id', $batch->id)->where('source_row', '>', $work->cursor)->exists();
            if ($more) {
                DB::table('bulk_work')->where('id', $work->id)->update(['lease_until' => null, 'lease_token' => null]);

                return;
            }
            $next = 'review';
            if ($work->phase === 'preparing') {
                $made = DB::table('bulk_drafts')->where('merchant_id', $batch->merchant_id)->where('batch_id', $batch->id)->count();
                $next = $made === $batch->row_count ? 'completed' : 'partial';
            }
            DB::table('bulk_batches')->where('id', $batch->id)->update(['state' => $next, 'version' => $batch->version + 1, 'updated_at' => now()]);
            DB::table('bulk_work')->where('id', $work->id)->update(['completed_at' => now(), 'lease_until' => null]);
            app(Imports::class)->event($batch, $work->phase.'_completed');
        });
    }

    private function validation(object $b, object $r): ?object
    {
        return DB::table('bulk_validations')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('row_id', $r->id)->where('mapping_version', $b->mapping_version)->first();
    }

    private function eligible(object $b, object $row): ?object
    {
        return app(Imports::class)->candidates($b)->where('v.row_id', $row->id)->select('v.*')->first();
    }

    private function validateRow(object $b, object $r): void
    {
        if ($this->validation($b, $r)) {
            return;
        }
        $mapping = json_decode(DB::table('bulk_mappings')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('version', $b->mapping_version)->value('mapping'), true);
        $raw = json_decode(Crypt::decryptString($r->raw), true);
        $draft = null;
        $hash = null;
        $duplicate = null;
        $errors = [];
        try {
            $draft = Columns::draft($raw, $mapping);
            $errors = app(DraftReadiness::class)->report($draft)['missing'];
        } catch (ValidationException $e) {
            $errors = array_keys($e->errors());
        } catch (ImportFailure $e) {
            $errors = ['packages_json_or_items_json'];
        } catch (HttpException $e) {
            $errors = ['city'];
        }
        if (! $errors) {
            $reference = '';
            foreach ($mapping as $i => $field) {
                if ($field === 'external_reference') {
                    $reference = trim($raw[$i] ?? '');
                }
            }$hash = hash_hmac('sha256', $b->merchant_id.ShipmentInput::digest(['draft' => $draft, 'reference' => $reference]), config('app.key'));
            $duplicate = DB::table('bulk_validations')->where('merchant_id', $b->merchant_id)->where('canonical_hash', $hash)->where('row_id', '<>', $r->id)->where(fn ($q) => $q->where('batch_id', '<>', $b->id)->orWhere('mapping_version', $b->mapping_version))->orderBy('created_at')->orderBy('id')->value('row_id');
        }
        DB::table('bulk_validations')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $b->merchant_id, 'batch_id' => $b->id, 'row_id' => $r->id, 'mapping_version' => $b->mapping_version, 'draft' => $draft ? Crypt::encryptString(json_encode($draft)) : null, 'errors' => json_encode($errors), 'canonical_hash' => $hash, 'duplicate_of' => $duplicate]);
    }

    private function priceRow(object $b, object $w, object $r): void
    {
        $v = $this->eligible($b, $r);
        if (! $v) {
            return;
        }
        if (DB::table('bulk_prices')->where('merchant_id', $b->merchant_id)->where('work_id', $w->id)->where('row_id', $r->id)->exists()) {
            return;
        }
        $public = null;
        $error = null;
        try {
            $public = app(ImportEstimates::class)->preview($b->merchant_id, json_decode(Crypt::decryptString($v->draft), true));
        } catch (\DomainException|HttpException $e) {
            $error = 'PRICING_UNAVAILABLE';
        }
        DB::table('bulk_prices')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $b->merchant_id, 'batch_id' => $b->id, 'row_id' => $r->id, 'work_id' => $w->id, 'mapping_version' => $b->mapping_version, 'public_snapshot' => $public ? json_encode($public) : null, 'error_code' => $error]);
    }

    private function prepareRow(object $b, object $r): void
    {
        $v = $this->eligible($b, $r);
        if (! $v) {
            return;
        }
        if (DB::table('bulk_drafts')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('row_id', $r->id)->exists()) {
            return;
        }
        $result = app(Shipments::class)->importDraft($b, json_decode(Crypt::decryptString($v->draft), true), 'bulk:'.$b->id.':'.$r->id);
        DB::table('bulk_drafts')->insert(['merchant_id' => $b->merchant_id, 'batch_id' => $b->id, 'row_id' => $r->id, 'shipment_id' => $result['shipment']->id ?? $result['shipment']['id']]);
        app(Imports::class)->event($b, 'draft_prepared', ['row_id' => $r->id, 'shipment_id' => $result['shipment']->id ?? $result['shipment']['id']]);
    }

    public function recover(): int
    {
        $count = 0;
        foreach (DB::table('bulk_work')->whereNull('completed_at')->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('created_at')->limit(100)->get() as $w) {
            ProcessImport::dispatch($w->merchant_id, $w->id);
            $count++;
        }

        return $count;
    }
}
