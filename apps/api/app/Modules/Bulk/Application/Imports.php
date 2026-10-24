<?php

namespace App\Modules\Bulk\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Bulk\Domain\Columns;
use App\Modules\Bulk\Infrastructure\PrivateFiles;
use App\Modules\Bulk\Jobs\ProcessImport;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\AvailableServices;
use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Imports
{
    public function batch(string $merchant, string $id): object
    {
        $b = DB::table('bulk_batches')->where('merchant_id', $merchant)->where('id', $id)->first();
        abort_unless($b, 404);

        return $b;
    }

    public function event(object $b, string $kind, array $metadata = [], ?string $actor = null): void
    {
        DB::table('bulk_events')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $b->merchant_id, 'batch_id' => $b->id, 'actor_id' => $actor ?? $b->actor_id, 'kind' => $kind, 'metadata' => json_encode($metadata)]);
        Audit::record('bulk.'.$kind, $actor ?? $b->actor_id, $b->merchant_id, $b->id);
    }

    public function origin(Request $r): array
    {
        $token = $r->user()->currentAccessToken();

        return ['actor_id' => $r->user()->id, 'auth_version' => $r->user()->auth_version, 'origin_token_id' => $token instanceof PersonalAccessToken ? $token->id : null, 'origin_session_id' => $token instanceof PersonalAccessToken ? null : $r->session()->get('auth_session_id')];
    }

    private function command(Request $r, MerchantContext $c, string $permission, string $op, array $payload, callable $write): string
    {
        $key = $r->validate(['idempotency_key' => 'required|string|min:8|max:128'])['idempotency_key'];

        return DB::transaction(function () use ($r, $c, $permission, $op, $payload, $key, $write) {
            app(ShippingAccess::class)->lock($r, $c, $permission);
            $q = DB::table('bulk_commands')->where('merchant_id', $c->id())->where('actor_id', $c->actorId())->where('operation', $op)->where('key_hash', hash('sha256', $key));
            $digest = ShipmentInput::digest($payload);
            if ($old = $q->first()) {
                abort_unless(hash_equals($old->digest, $digest), 409);

                return json_decode($old->result, true)['batch_id'];
            }
            $id = $write();
            DB::table('bulk_commands')->insert(['merchant_id' => $c->id(), 'actor_id' => $c->actorId(), 'operation' => $op, 'key_hash' => hash('sha256', $key), 'digest' => $digest, 'result' => json_encode(['batch_id' => $id])]);

            return $id;
        });
    }

    public function initialize(Request $r, MerchantContext $c): string
    {
        $d = $r->validate(['format' => 'required|in:csv,xlsx', 'delimiter' => 'sometimes|string|max:3', 'merchant_id' => 'prohibited']);
        // Explicit delimiter validation avoids interpreting a comma-separated validation rule as a CSV dialect.
        $delimiter = $r->input('delimiter', ',');
        if ($delimiter === 'tab') {
            $delimiter = "\t";
        }
        abort_unless(in_array($delimiter, [',', ';', "\t"], true), 422);

        return $this->command($r, $c, 'bulk.upload', 'initialize', [$d['format'], $delimiter], function () use ($r, $c, $d, $delimiter) {
            $pending = DB::table('bulk_batches')->where('merchant_id', $c->id())->whereNotIn('state', ['completed', 'partial', 'cancelled', 'failed', 'blocked'])->count();
            abort_if($pending >= config('bulk.max_pending_batches'), 429);
            abort_if(DB::table('bulk_batches')->where('merchant_id', $c->id())->where('created_at', '>=', now()->subDay())->count() >= config('bulk.max_daily_imports'), 429);
            $id = (string) Str::uuid();
            DB::table('bulk_batches')->insert(['id' => $id, 'merchant_id' => $c->id(), 'format' => $d['format'], 'delimiter' => $delimiter] + $this->origin($r));
            $this->event($this->batch($c->id(), $id), 'initialized');

            return $id;
        });
    }

    public function work(object $b, string $phase, array $origin): void
    {
        $id = (string) Str::uuid();
        DB::table('bulk_batches')->where('id', $b->id)->update($origin + ['state' => $phase, 'version' => $b->version + 1, 'updated_at' => now()]);
        DB::table('bulk_work')->insert(['id' => $id, 'merchant_id' => $b->merchant_id, 'batch_id' => $b->id, 'phase' => $phase, 'mapping_version' => $b->mapping_version]);
        $this->event($this->batch($b->merchant_id, $b->id), 'work_requested', ['work_id' => $id, 'phase' => $phase]);
        DB::afterCommit(fn () => ProcessImport::dispatch($b->merchant_id, $id));
    }

    public function upload(Request $r, MerchantContext $c, string $id): string
    {
        $r->validate(['file' => 'required|file|max:'.(int) ceil(config('bulk.max_bytes') / 1024), 'version' => 'required|integer|min:1', 'merchant_id' => 'prohibited']);
        $file = $r->file('file');
        abort_if($file->getSize() > config('bulk.max_bytes') || $file->getSize() === 0, 422);
        $hash = hash_file('sha256', $file->getRealPath());

        return $this->command($r, $c, 'bulk.upload', 'upload:'.$id, [$hash, $file->getSize(), $r->integer('version')], function () use ($r, $c, $id, $file, $hash) {
            $b = $this->batch($c->id(), $id);
            abort_unless($b->state === 'initialized' && $b->version === $r->integer('version'), 409);
            abort_unless(strtolower($file->getClientOriginalExtension()) === $b->format, 422);
            abort_if(DB::table('bulk_files')->where('merchant_id', $c->id())->sum('size_bytes') + $file->getSize() > config('bulk.max_retained_bytes'), 429);
            $fid = (string) Str::uuid();
            $storage = app(PrivateFiles::class)->store($file->getRealPath(), $fid);
            DB::table('bulk_files')->insert(['id' => $fid, 'merchant_id' => $c->id(), 'batch_id' => $id, 'storage_key' => $storage, 'filename' => Crypt::encryptString(mb_substr(basename($file->getClientOriginalName()), 0, 200)), 'sha256' => $hash, 'size_bytes' => $file->getSize()]);
            $repeat = DB::table('bulk_files')->where('merchant_id', $c->id())->where('sha256', $hash)->where('batch_id', '<>', $id)->orderBy('created_at')->value('batch_id');
            DB::table('bulk_batches')->where('id', $id)->update(['repeat_of' => $repeat]);
            $this->work($b, 'parsing', $this->origin($r));

            return $id;
        });
    }

    public function mapping(Request $r, MerchantContext $c, string $id): string
    {
        $d = $r->validate(['mapping' => 'required|array|min:1|max:96', 'version' => 'required|integer|min:1', 'merchant_id' => 'prohibited']);

        return $this->command($r, $c, 'bulk.map', 'mapping:'.$id, $d, function () use ($r, $c, $id, $d) {
            $b = $this->batch($c->id(), $id);
            abort_unless(in_array($b->state, ['mapping', 'review']) && $b->version === $d['version'], 409);
            $headers = json_decode(Crypt::decryptString($b->headers), true);
            $seen = [];
            foreach ($d['mapping'] as $i => $field) {
                abort_unless(ctype_digit((string) $i) && (int) $i < count($headers) && is_string($field) && isset(Columns::labels()[$field]) && ! isset($seen[$field]), 422);
                $seen[$field] = true;
            }
            $version = $b->mapping_version + 1;
            DB::table('bulk_mappings')->insert(['merchant_id' => $c->id(), 'batch_id' => $id, 'version' => $version, 'mapping' => json_encode($d['mapping']), 'actor_id' => $c->actorId()]);
            DB::table('bulk_batches')->where('id', $id)->update(['mapping_version' => $version]);
            $b->mapping_version = $version;
            $this->work($b, 'validating', $this->origin($r));

            return $id;
        });
    }

    public function decision(Request $r, MerchantContext $c, string $id, string $row): string
    {
        $d = $r->validate(['decision' => 'required|in:keep,skip', 'reason' => 'required|string|min:3|max:500', 'version' => 'required|integer|min:1', 'merchant_id' => 'prohibited']);

        return $this->command($r, $c, 'bulk.map', 'decision:'.$id.':'.$row, $d, function () use ($c, $id, $row, $d) {
            $b = $this->batch($c->id(), $id);
            abort_unless($b->state === 'review' && $b->version === $d['version'], 409);
            $v = DB::table('bulk_validations')->where('merchant_id', $c->id())->where('batch_id', $id)->where('row_id', $row)->where('mapping_version', $b->mapping_version)->first();
            abort_unless($v, 404);
            abort_unless($v->duplicate_of, 409);
            abort_if(DB::table('bulk_decisions')->where('merchant_id', $c->id())->where('batch_id', $id)->where('row_id', $row)->where('mapping_version', $b->mapping_version)->exists(), 409);
            DB::table('bulk_decisions')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'batch_id' => $id, 'row_id' => $row, 'mapping_version' => $b->mapping_version, 'decision' => $d['decision'], 'reason' => $d['reason'], 'actor_id' => $c->actorId()]);
            DB::table('bulk_batches')->where('id', $id)->increment('version');
            $this->event($b, 'duplicate_decided', ['row_id' => $row, 'decision' => $d['decision']], $c->actorId());

            return $id;
        });
    }

    public function candidates(object $b)
    {
        return DB::table('bulk_validations as v')->leftJoin('bulk_decisions as d', function ($j) {
            $j->on('d.merchant_id', '=', 'v.merchant_id')->on('d.batch_id', '=', 'v.batch_id')->on('d.row_id', '=', 'v.row_id')->on('d.mapping_version', '=', 'v.mapping_version');
        })->join('bulk_rows as r', 'r.id', '=', 'v.row_id')->where('v.merchant_id', $b->merchant_id)->where('v.batch_id', $b->id)->where('v.mapping_version', $b->mapping_version)->whereRaw('jsonb_array_length(v.errors)=0')->where(fn ($q) => $q->whereNull('v.duplicate_of')->orWhere('d.decision', 'keep'))->where(fn ($q) => $q->whereNull('d.decision')->orWhere('d.decision', 'keep'));
    }

    public function start(Request $r, MerchantContext $c, string $id, string $phase): string
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'include_unpriced' => 'sometimes|boolean', 'merchant_id' => 'prohibited']);
        $permission = $phase === 'pricing' ? 'bulk.price' : 'bulk.prepare';

        return $this->command($r, $c, $permission, $phase.':'.$id, $d, function () use ($r, $c, $id, $phase, $d) {
            $c->require($phase === 'pricing' ? 'rates.quote' : 'shipments.write');
            $b = $this->batch($c->id(), $id);
            abort_unless($b->state === 'review' && $b->version === $d['version'], 409);
            if ($phase === 'preparing') {
                $unresolved = DB::table('bulk_validations as v')->where('v.merchant_id', $c->id())->where('v.batch_id', $id)->where('v.mapping_version', $b->mapping_version)->whereNotNull('v.duplicate_of')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bulk_decisions as d')->whereColumn('d.row_id', 'v.row_id')->whereColumn('d.mapping_version', 'v.mapping_version')->whereColumn('d.batch_id', 'v.batch_id')->whereColumn('d.merchant_id', 'v.merchant_id'))->exists();
                abort_if($unresolved, 409);
                $count = $this->candidates($b)->count();
                abort_unless($count > 0, 422);
                // Unpriced drafts are allowed only with explicit acknowledgement. No quote is accepted by preparation.
                $priced = $this->latestPrices($b)->whereNotNull('public_snapshot')->count();
                abort_if($priced < $count && ! ($d['include_unpriced'] ?? false), 422);
                $hash = hash_init('sha256');
                foreach ($this->candidates($b)->orderBy('r.source_row')->select('v.row_id', 'v.canonical_hash')->cursor() as $row) {
                    hash_update($hash, $row->row_id.$row->canonical_hash);
                }
                DB::table('bulk_approvals')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $c->id(), 'batch_id' => $id, 'mapping_version' => $b->mapping_version, 'actor_id' => $c->actorId(), 'include_unpriced' => $d['include_unpriced'] ?? false, 'row_set_hash' => hash_final($hash)]);
            }
            $this->work($b, $phase, $this->origin($r));

            return $id;
        });
    }

    public function cancel(Request $r, MerchantContext $c, string $id): string
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'merchant_id' => 'prohibited']);

        return $this->command($r, $c, 'bulk.cancel', 'cancel:'.$id, $d, function () use ($c, $id, $d) {
            $b = $this->batch($c->id(), $id);
            abort_unless($b->version === $d['version'] && ! in_array($b->state, ['completed', 'partial', 'cancelled']), 409);
            abort_if(DB::table('bulk_drafts')->where('merchant_id', $c->id())->where('batch_id', $id)->exists(), 409);
            DB::table('bulk_batches')->where('id', $id)->update(['state' => 'cancelled', 'version' => $b->version + 1, 'updated_at' => now()]);
            DB::table('bulk_work')->where('merchant_id', $c->id())->where('batch_id', $id)->whereNull('completed_at')->update(['completed_at' => now()]);
            $this->event($b, 'cancelled', [], $c->actorId());

            return $id;
        });
    }

    public function latestPrices(object $b)
    {
        $work = DB::table('bulk_work')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('phase', 'pricing')->where('mapping_version', $b->mapping_version)->orderByDesc('created_at')->orderByDesc('id')->value('id');

        return DB::table('bulk_prices')->where('merchant_id', $b->merchant_id)->where('batch_id', $b->id)->where('work_id', $work);
    }

    public function show(string $merchant, string $id): array
    {
        $b = $this->batch($merchant, $id);
        $headers = $b->headers ? json_decode(Crypt::decryptString($b->headers), true) : [];
        $mapping = DB::table('bulk_mappings')->where('merchant_id', $merchant)->where('batch_id', $id)->where('version', $b->mapping_version)->value('mapping');
        $v = DB::table('bulk_validations')->where('merchant_id', $merchant)->where('batch_id', $id)->where('mapping_version', $b->mapping_version);
        $valid = (clone $v)->whereRaw('jsonb_array_length(errors)=0')->count();
        $invalid = (clone $v)->whereRaw('jsonb_array_length(errors)>0')->count();
        $duplicates = (clone $v)->whereNotNull('duplicate_of')->count();
        $prepared = DB::table('bulk_drafts')->where('merchant_id', $merchant)->where('batch_id', $id)->count();
        $totals = [];
        $priced = 0;
        $expired = 0;
        foreach ($this->latestPrices($b)->cursor() as $p) {
            if ($p->public_snapshot) {
                $q = json_decode($p->public_snapshot, true);
                $currency = $q['currency'];
                $totals[$currency] = bcadd($totals[$currency] ?? '0', $q['total_minor'], 0);
                $priced++;
                if (now()->gte($q['expires_at']) || ! app(AvailableServices::class)->contains($q['service_id'])) {
                    $expired++;
                }
            }
        }

        $work = DB::table('bulk_work')->where('merchant_id', $merchant)->where('batch_id', $id)->whereNull('completed_at')->first();
        $processed = $work ? DB::table('bulk_rows')->where('merchant_id', $merchant)->where('batch_id', $id)->where('source_row', '<=', $work->cursor)->count() : $b->row_count;
        $file = DB::table('bulk_files')->where('merchant_id', $merchant)->where('batch_id', $id)->first();

        return ['progress' => ['phase' => $work?->phase ?? $b->state, 'processed_rows' => $processed, 'total_rows' => $b->state === 'parsing' ? null : $b->row_count], 'file' => $file ? ['filename' => Crypt::decryptString($file->filename), 'size_bytes' => $file->size_bytes] : null, 'batch' => ['id' => $id, 'state' => $b->state, 'version' => $b->version, 'mapping_version' => $b->mapping_version, 'format' => $b->format, 'row_count' => $b->row_count, 'repeat_of' => $b->repeat_of, 'failure_code' => $b->failure_code, 'created_at' => $b->created_at], 'headers' => $headers, 'mapping' => $mapping ? json_decode($mapping, true) : Columns::suggest($headers), 'columns' => Columns::labels(), 'counts' => compact('valid', 'invalid', 'duplicates', 'prepared', 'priced', 'expired'), 'totals_minor' => $totals, 'submission_enabled' => false, 'limits' => ['max_bytes' => config('bulk.max_bytes'), 'max_rows' => config('bulk.max_rows'), 'chunk_rows' => config('bulk.chunk_rows')]];
    }
}
