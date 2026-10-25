<?php

namespace App\Modules\Analytics\Application;

use App\Models\PersonalAccessToken;
use App\Modules\Analytics\Jobs\GenerateReport;
use App\Modules\Identity\Application\Audit;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Shipping\Domain\DocumentScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class Exports
{
    public function available(): bool
    {
        return app()->environment('testing') && in_array(DB::connection()->getDatabaseName(), ['clicktoship_test', 'clicktoship_e2e'], true) && config('analytics.test_artifacts_enabled') === true;
    }

    public function event(string $id, string $kind, ?string $artifact = null): void
    {
        DB::table('report_export_events')->insert(['id' => (string) Str::uuid(), 'export_id' => $id, 'kind' => $kind, 'artifact' => $artifact]);
    }

    public function request(Request $r, string $scope, ?string $merchant, array $p): array
    {
        abort_unless(in_array($scope === 'merchant' ? 'analytics.export' : 'platform.analytics.export', $p, true), 403);
        $d = $r->validate(['report' => 'required|string|max:50', 'format' => 'required|in:csv,xlsx', 'locale' => 'required|in:en,ar', 'idempotency_key' => 'required|string|min:8|max:128', 'after' => 'prohibited', 'scope' => 'prohibited', 'actor_id' => 'prohibited', 'origin_session_id' => 'prohibited', 'origin_token_id' => 'prohibited']);
        if ($scope === 'platform') {
            abort_unless(in_array($d['report'], Overview::SECTIONS, true), 422);
        }
        app(Authority::class)->require($p, $d['report'], $scope);
        if ($scope === 'merchant' && Definitions::financial($d['report'])) {
            abort_if($r->user()->currentAccessToken() instanceof PersonalAccessToken, 403);
            PlatformSecurity::fresh($r);
        }
        $f = Filters::read($r, $scope === 'merchant' ? $d['report'] : null);
        if ($scope === 'platform') {
            foreach (['provider', 'currency', 'status', 'service'] as $key) {
                abort_if(isset($f[$key]), 422);
            }
        }
        DB::select('SELECT pg_advisory_xact_lock(1500015)');
        $hash = hash('sha256', $r->user()->id.'|'.$d['idempotency_key']);
        $digest = hash('sha256', json_encode([$scope, $merchant, $d['report'], $d['format'], $d['locale'], $f]));
        $old = DB::table('report_exports')->where('actor_id', $r->user()->id)->where('key_hash', $hash)->first();
        if ($old) {
            abort_unless(hash_equals($old->digest, $digest), 409);

            return ['export' => $this->public($old), 'replayed' => true];
        }
        abort_if(DB::table('report_exports')->where('expires_at', '>', now())->whereIn('state', ['queued', 'processing', 'ready'])->count() >= 100, 429);
        abort_if(DB::table('report_exports')->where('actor_id', $r->user()->id)->where('expires_at', '>', now())->whereIn('state', ['queued', 'processing'])->count() >= 3, 429);
        abort_if(DB::table('report_exports')->where('actor_id', $r->user()->id)->where('digest', $digest)->whereIn('state', ['queued', 'processing'])->exists(), 409);
        if ($merchant) {
            abort_if(DB::table('report_exports')->where('merchant_id', $merchant)->whereIn('state', ['queued', 'processing'])->where('expires_at', '>', now())->count() >= 3, 429);
        }
        $id = (string) Str::uuid();
        DB::table('report_exports')->insert(['id' => $id, 'scope' => $scope, 'merchant_id' => $merchant, 'report' => $d['report'], 'format' => $d['format'], 'locale' => $d['locale'], 'filters' => json_encode($f), 'key_hash' => $hash, 'digest' => $digest, 'state' => 'requested'] + app(Authority::class)->origin($r));
        $this->event($id, 'requested');
        $this->event($id, 'authorized');
        $state = $this->available() ? 'queued' : 'failed';
        DB::table('report_exports')->where('id', $id)->update(['state' => $state, 'error_code' => $state === 'failed' ? 'EXPORT_STORAGE_NOT_APPROVED' : null]);
        $this->event($id, $state);
        Audit::record('analytics.export.requested', $r->user()->id, $merchant, $id, ['report' => $d['report'], 'format' => $d['format'], 'state' => $state]);
        if ($state === 'queued') {
            DB::afterCommit(fn () => GenerateReport::dispatch($id));
        }

        return ['export' => $this->public(DB::table('report_exports')->where('id', $id)->first()), 'artifacts_enabled' => $this->available()];
    }

    private function public(object $e): array
    {
        $value = array_intersect_key((array) $e, array_flip(['id', 'report', 'format', 'locale', 'state', 'rows', 'bytes', 'error_code', 'created_at', 'generated_at', 'expires_at', 'actor_id', 'filters']));
        $value['filters'] = json_decode($e->filters, true);
        $value['from'] = $value['filters']['period']['from'];
        $value['to'] = $value['filters']['period']['to'];
        if (in_array($e->state, ['queued', 'processing', 'ready'], true) && now()->gte($e->expires_at)) {
            $value['state'] = 'expired';
        }

        return $value;
    }

    private function owned(Request $r, string $scope, ?string $m, string $id, array $p): object
    {
        $e = DB::table('report_exports')->where('scope', $scope)->where('merchant_id', $m)->where('actor_id', $r->user()->id)->where('id', $id)->lockForUpdate()->first();
        abort_unless($e, 404);
        app(Authority::class)->require($p, $e->report, $scope);

        return $e;
    }

    public function history(Request $r, string $scope, ?string $m, array $p, ?string $id = null): array
    {
        if ($id) {
            return ['export' => $this->public($this->owned($r, $scope, $m, $id, $p)), 'artifacts_enabled' => $this->available()];
        }
        $d = $r->validate(['after' => 'sometimes|uuid']);
        $reports = $scope === 'merchant' ? array_column(Definitions::catalog($p), 'id') : array_values(array_filter(Overview::SECTIONS, fn ($s) => in_array('platform.metrics.'.$s, $p, true)));
        $q = DB::table('report_exports')->where('scope', $scope)->where('merchant_id', $m)->where('actor_id', $r->user()->id)->whereIn('report', $reports);
        if (isset($d['after'])) {
            $q->where('id', '<', $d['after']);
        }
        $rows = $q->orderByDesc('id')->limit(26)->get();
        $more = $rows->count() > 25;
        $rows = $rows->take(25);

        return ['exports' => $rows->map(fn ($e) => $this->public($e))->values(), 'next_after' => $more ? $rows->last()->id : null, 'artifacts_enabled' => $this->available()];
    }

    public function revoke(Request $r, string $scope, ?string $m, array $p, string $id): array
    {
        $e = $this->owned($r, $scope, $m, $id, $p);
        $this->terminal($e, 'revoked', 'EXPORT_REVOKED');

        return ['revoked' => true];
    }

    private function terminal(object $e, string $state, string $error): void
    {
        foreach (DB::table('report_export_events')->where('export_id', $e->id)->whereNotNull('artifact')->limit(3)->pluck('artifact') as $artifact) {
            Storage::disk(config('analytics.disk'))->delete($artifact);
        }
        DB::table('report_exports')->where('id', $e->id)->update(['state' => $state, 'error_code' => $error, 'lease' => null, 'lease_until' => null, 'artifact' => null]);
        $this->event($e->id, $state);
    }

    public function tick(string $id): void
    {
        app(MerchantContext::class)->clear();
        $path = null;
        $lease = (string) Str::uuid();
        try {
            $e = DB::table('report_exports')->where('id', $id)->first();
            if (! $e) {
                return;
            }
            $claimed = app(Authority::class)->worker($e, function () use ($id, $lease) {
                DB::select('SELECT pg_advisory_xact_lock(1500015)');
                $e = DB::table('report_exports')->where('id', $id)->lockForUpdate()->first();
                if (! in_array($e->state, ['queued', 'processing'], true) || now()->lt($e->next_at) || ($e->lease_until && now()->lt($e->lease_until))) {
                    return null;
                }
                if (now()->gte($e->expires_at)) {
                    $this->terminal($e, 'expired', 'EXPORT_EXPIRED');

                    return null;
                }
                if ($e->attempt >= 3) {
                    $this->terminal($e, 'failed', 'EXPORT_RECOVERY_LIMIT');

                    return null;
                }
                if (! $this->available()) {
                    $this->terminal($e, 'failed', 'EXPORT_STORAGE_NOT_APPROVED');

                    return null;
                }
                if (DB::table('report_exports')->where('state', 'processing')->where('lease_until', '>', now())->count() >= 4) {
                    return null;
                }
                if ($e->merchant_id && DB::table('report_exports')->where('state', 'processing')->where('merchant_id', $e->merchant_id)->where('lease_until', '>', now())->exists()) {
                    return null;
                }
                if (DB::table('report_exports')->where('state', 'processing')->where('actor_id', $e->actor_id)->where('lease_until', '>', now())->exists()) {
                    return null;
                }
                foreach (DB::table('report_export_events')->where('export_id', $e->id)->whereNotNull('artifact')->limit(3)->pluck('artifact') as $artifact) {
                    Storage::disk(config('analytics.disk'))->delete($artifact);
                }
                $path = 'analytics/'.$id.'/'.$lease.'.enc';
                DB::table('report_exports')->where('id', $id)->update(['state' => 'processing', 'attempt' => $e->attempt + 1, 'lease' => $lease, 'lease_until' => now()->addSeconds(60), 'artifact' => $path]);
                $this->event($id, 'processing', $path);
                $e->artifact = $path;

                return $e;
            });
            if (! $claimed) {
                return;
            }$path = $claimed->artifact;
            $result = app(Authority::class)->worker($claimed, function ($p) use ($claimed) {
                $f = json_decode($claimed->filters, true);
                if ($claimed->scope === 'merchant') {
                    return app(Reports::class)->read($claimed->report, $claimed->merchant_id, $f, 1000, false);
                }
                $data = app(Overview::class)->platform($p, $f, $claimed->report);
                $rows = [];
                $flatten = function ($value, $key = '') use (&$flatten, &$rows) {
                    if (is_array($value) || is_object($value)) {
                        foreach ((array) $value as $k => $v) {
                            $flatten($v, ltrim($key.'.'.$k, '.'));
                        }
                    } else {
                        $rows[] = ['metric' => $key, 'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value];
                    }
                };
                $flatten($data['widgets']);

                return ['columns' => ['metric', 'value'], 'rows' => $rows, 'next_after' => null];
            }, true);
            abort_if($result['next_after'] || count($result['rows']) > 1000, 413);
            $rows = array_map(fn ($r) => (array) $r, is_array($result['rows']) ? $result['rows'] : $result['rows']->all());
            $bytes = app(Spreadsheet::class)->write($result['columns'], $rows, $claimed->format, $claimed->locale);
            abort_unless(app(DocumentScanner::class)->clean($bytes), 503);
            abort_unless(Storage::disk(config('analytics.disk'))->put($path, Crypt::encryptString($bytes), ['visibility' => 'private']), 503);
            app(Authority::class)->worker($claimed, function () use ($id, $lease, $bytes, $rows) {
                $e = DB::table('report_exports')->where('id', $id)->lockForUpdate()->first();
                abort_unless($e->state === 'processing' && $e->lease === $lease && now()->lt($e->lease_until) && now()->lt($e->expires_at), 409);
                DB::table('report_exports')->where('id', $id)->update(['state' => 'ready', 'rows' => count($rows), 'bytes' => strlen($bytes), 'artifact_hash' => hash('sha256', $bytes), 'generated_at' => now(), 'lease' => null, 'lease_until' => null]);
                $this->event($id, 'ready');
            });
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk(config('analytics.disk'))->delete($path);
            }
            DB::transaction(function () use ($id, $lease, $error) {
                $e = DB::table('report_exports')->where('id', $id)->lockForUpdate()->first();
                if (! $e || ! in_array($e->state, ['queued', 'processing'], true) || ($e->lease && $e->lease !== $lease)) {
                    return;
                }$code = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503;
                $this->terminal($e, in_array($code, [401, 403, 404], true) ? 'revoked' : 'failed', $code === 413 ? 'EXPORT_LIMIT_EXCEEDED' : (in_array($code, [401, 403, 404], true) ? 'EXPORT_AUTHORITY_REVOKED' : 'EXPORT_GENERATION_FAILED'));
            });
        } finally {
            app(MerchantContext::class)->clear();
        }
    }

    public function download(Request $r, string $scope, ?string $m, array $p, string $id): mixed
    {
        abort_unless($this->available(), 503);
        $e = $this->owned($r, $scope, $m, $id, $p);
        $origin = app(Authority::class)->origin($r);
        abort_unless($origin['origin_token_id'] === $e->origin_token_id && $origin['origin_session_id'] === $e->origin_session_id, 403);

        return app(Authority::class)->worker($e, function () use ($e) {
            abort_unless($e->state === 'ready' && now()->lt($e->expires_at), 410);
            abort_unless(Storage::disk(config('analytics.disk'))->exists($e->artifact) && Storage::disk(config('analytics.disk'))->size($e->artifact) <= 4194304, 503);
            $encrypted = Storage::disk(config('analytics.disk'))->get($e->artifact);
            $bytes = Crypt::decryptString($encrypted);
            abort_unless(strlen($bytes) === $e->bytes && hash_equals($e->artifact_hash, hash('sha256', $bytes)), 503);
            $this->event($e->id, 'downloaded');

            return response($bytes)->header('Content-Type', $e->format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')->header('Content-Disposition', 'attachment; filename="report.'.$e->format.'"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        });
    }

    public function recover(): int
    {
        $n = 0;
        foreach (DB::table('report_exports')->whereIn('state', ['queued', 'processing', 'ready'])->orderBy('id')->limit(100)->get() as $e) {
            if (now()->gte($e->expires_at)) {
                DB::transaction(function () use ($e) {
                    $fresh = DB::table('report_exports')->where('id', $e->id)->lockForUpdate()->first();
                    if (in_array($fresh->state, ['queued', 'processing', 'ready'], true) && now()->gte($fresh->expires_at)) {
                        $this->terminal($fresh, 'expired', 'EXPORT_EXPIRED');
                    }
                });
            } elseif ($e->state !== 'ready' && (! $e->lease_until || now()->gte($e->lease_until))) {
                GenerateReport::dispatch($e->id);
                $n++;
            }
        }
        foreach (DB::table('report_exports')->where('expires_at', '<=', now())->orderBy('last_cleanup_at')->orderBy('id')->limit(100)->get() as $expired) {
            foreach (DB::table('report_export_events')->where('export_id', $expired->id)->whereNotNull('artifact')->limit(3)->pluck('artifact') as $path) {
                Storage::disk(config('analytics.disk'))->delete($path);
            }DB::table('report_exports')->where('id', $expired->id)->update(['last_cleanup_at' => now()]);
        }

        return $n;
    }
}
