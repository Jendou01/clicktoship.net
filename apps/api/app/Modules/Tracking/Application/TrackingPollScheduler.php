<?php

namespace App\Modules\Tracking\Application;

use App\Modules\Shipping\Application\ShippingAccess;
use App\Modules\Tracking\Jobs\ProcessTracking;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TrackingPollScheduler
{
    private function authority(object $w): void
    {
        abort_unless(DB::table('merchants')->where('id', $w->merchant_id)->where('status', 'active')->exists(), 403);
        $b = DB::table('tracking_bindings')->where('merchant_id', $w->merchant_id)->where('id', $w->binding_id)->first();
        abort_unless($b && $b->account_id === $w->account_id, 409);
        $synthetic = app(TrackingProviderRegistry::class)->for($w->account_id)->capabilities()['synthetic'] ?? false;
        abort_unless($b->provenance === ($synthetic ? 'synthetic_test_only' : 'verified'), 503);
        $op = DB::table('carrier_operations')->where('id', $b->operation_id)->first();
        $account = DB::table('carrier_accounts')->where('id', $b->account_id)->first();
        abort_unless($account && $op && $account->active_version === $op->account_version, 403);
        if ($w->kind === 'poll') {
            app(ShippingAccess::class)->worker($w, 'tracking.refresh');
        }
        if ($w->kind === 'webhook') {
            abort_unless(DB::table('tracking_endpoints')->where('id', $w->endpoint_id)->where('enabled', true)->exists(), 403);
        }
        abort_unless(app(TrackingProviderRegistry::class)->allowed($w->account_id, $w->kind === 'webhook' ? 'webhook' : 'poll'), 503);
    }

    public function tick(string $merchant, string $id): void
    {
        $token = (string) Str::uuid();
        $w = DB::transaction(function () use ($merchant, $id, $token) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $q = DB::table('tracking_work')->where('merchant_id', $merchant)->where('id', $id);
            $w = $q->lockForUpdate()->first();
            if (! $w || ! in_array($w->state, ['pending', 'processing']) || now()->lt($w->available_at) || ($w->lease_until && now()->lt($w->lease_until))) {
                return null;
            }
            if ($w->attempts >= 5) {
                $q->update(['state' => 'dead_letter', 'error_code' => 'ATTEMPTS_EXHAUSTED', 'lease_until' => null]);

                return null;
            }
            try {
                $this->authority($w);
            } catch (HttpExceptionInterface $e) {
                $q->update(['state' => 'blocked', 'error_code' => $e->getStatusCode() === 503 ? 'CONTRACT_UNVERIFIED' : 'AUTHORITY_REVOKED', 'lease_until' => null]);

                return null;
            }
            if ($w->kind !== 'webhook') {
                DB::table('tracking_account_health')->insertOrIgnore(['account_id' => $w->account_id]);
                $h = DB::table('tracking_account_health')->where('account_id', $w->account_id)->lockForUpdate()->first();
                $next = max(strtotime($h->next_call_at), $h->circuit_until ? strtotime($h->circuit_until) : 0);
                if ($next > now()->timestamp) {
                    $q->update(['state' => 'pending', 'available_at' => date('c', $next), 'lease_until' => null]);

                    return null;
                }
                DB::table('tracking_account_health')->where('account_id', $w->account_id)->update(['next_call_at' => now()->addSeconds(config('tracking.account_spacing_seconds'))]);
            }
            $q->update(['state' => 'processing', 'attempts' => $w->attempts + 1, 'lease_token' => $token, 'lease_until' => now()->addSeconds(90), 'updated_at' => now()]);
            $w = $q->select('tracking_work.*')->first();
            DB::table('tracking_attempts')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'work_id' => $id, 'number' => $w->attempts, 'outcome' => 'started']);

            return $w;
        });
        if (! $w) {
            return;
        }
        try {
            $b = DB::table('tracking_bindings')->where('merchant_id', $merchant)->where('id', $w->binding_id)->first();
            $adapter = app(TrackingProviderRegistry::class)->for($w->account_id);
            // No locks held across provider reads. Adapters own timeouts and never receive a user-controlled URL.
            $events = $w->kind === 'webhook' ? json_decode(Crypt::decryptString($w->payload_ciphertext), true, 32, JSON_THROW_ON_ERROR) : $adapter->poll($b);
            DB::transaction(function () use ($merchant, $id, $token, $events, $adapter, $b) {
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                $w = DB::table('tracking_work')->where('merchant_id', $merchant)->where('id', $id)->lockForUpdate()->first();
                if ($w->state !== 'processing' || $w->lease_token !== $token || now()->gte($w->lease_until)) {
                    return;
                }
                $this->authority($w);
                DB::table('tracking_bindings')->where('id', $b->id)->lockForUpdate()->firstOrFail();
                app(TrackingEventStore::class)->append($b, $w, $events, $adapter);
                DB::table('tracking_attempts')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'work_id' => $id, 'number' => $w->attempts, 'outcome' => 'completed']);
                DB::table('tracking_work')->where('id', $id)->update(['state' => 'completed', 'lease_until' => null, 'error_code' => null, 'updated_at' => now()]);
                if ($w->kind !== 'webhook') {
                    DB::table('tracking_account_health')->where('account_id', $w->account_id)->update(['failures' => 0, 'circuit_until' => null]);
                }
            });
        } catch (\Throwable $e) {
            // Redacted classification only. Do not log exception text/provider bytes.
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof ValidationException ? 422 : 500);
            DB::transaction(function () use ($merchant, $id, $token, $status) {
                DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
                $q = DB::table('tracking_work')->where('merchant_id', $merchant)->where('id', $id);
                $w = $q->lockForUpdate()->first();
                if ($w->state !== 'processing' || $w->lease_token !== $token || now()->gte($w->lease_until)) {
                    return;
                }
                $state = match (true) {
                    $status === 403 => 'blocked',in_array($status, [409, 422], true) => 'quarantined',$status === 503 => 'blocked',$w->attempts >= 5 => 'dead_letter',default => 'pending'
                };
                $code = match ($state) {
                    'blocked' => 'AUTHORITY_OR_EVIDENCE_BLOCKED','quarantined' => 'INVALID_OR_CONFLICTING_EVENT','dead_letter' => 'ATTEMPTS_EXHAUSTED',default => 'RETRYABLE_PROVIDER_READ'
                };
                $q->update(['state' => $state, 'error_code' => $code, 'available_at' => now()->addSeconds(min(3600, 30 * (2 ** $w->attempts))), 'lease_until' => null, 'updated_at' => now()]);
                DB::table('tracking_attempts')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $merchant, 'work_id' => $id, 'number' => $w->attempts, 'outcome' => $state]);
                if ($w->kind !== 'webhook') {
                    DB::table('tracking_account_health')->where('account_id', $w->account_id)->increment('failures');
                    $h = DB::table('tracking_account_health')->where('account_id', $w->account_id)->first();
                    if ($h->failures >= 3) {
                        DB::table('tracking_account_health')->where('account_id', $w->account_id)->update(['circuit_until' => now()->addMinutes(15)]);
                    }
                }
            });
        }
    }

    public function recover(): int
    {
        $count = 0;
        DB::table('tracking_work')->whereIn('state', ['pending', 'processing'])->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
            foreach ($rows as $w) {
                ProcessTracking::dispatch($w->merchant_id, $w->id);
                $count++;
            }
        });

        return $count;
    }

    public function schedule(): int
    {
        return DB::transaction(function () {
            if (! DB::selectOne('SELECT pg_try_advisory_xact_lock(9092026) AS acquired')->acquired) {
                return 0;
            }
            $count = 0;
            foreach (['observations', 'bindings'] as $name) {
                DB::table('tracking_scan_cursors')->insertOrIgnore(['name' => $name]);
                $cursor = DB::table('tracking_scan_cursors')->where('name', $name)->lockForUpdate()->value('cursor');
                $q = DB::table($name === 'observations' ? 'shipping_observations' : 'tracking_bindings')->when($cursor, fn ($q) => $q->where('id', '>', $cursor))->orderBy('id')->limit(100);
                if ($name === 'observations') {
                    $q->where('outcome', 'accepted');
                }$rows = $q->get();
                foreach ($rows as $row) {
                    if ($name === 'observations') {
                        $this->backfill($row);

                        continue;
                    }
                    $b = $row;
                    if (! app(TrackingProviderRegistry::class)->allowed($b->account_id, 'poll')) {
                        continue;
                    }
                    $m = DB::table('merchants')->where('id', $b->merchant_id)->lockForUpdate()->first();
                    if (! $m || $m->status !== 'active') {
                        continue;
                    }
                    if (DB::table('tracking_work')->where('binding_id', $b->id)->whereIn('kind', ['poll', 'scheduled'])->where(fn ($q) => $q->whereIn('state', ['pending', 'processing'])->orWhere('created_at', '>', now()->subSeconds(config('tracking.interval_seconds'))))->exists()) {
                        continue;
                    }
                    $p = DB::table('carrier_tracking_projections')->where('operation_id', $b->operation_id)->first();
                    if ($p && in_array($p->status, ['delivered', 'returned', 'cancelled'])) {
                        continue;
                    }
                    $id = (string) Str::uuid();
                    DB::table('tracking_work')->insert(['id' => $id, 'merchant_id' => $b->merchant_id, 'binding_id' => $b->id, 'account_id' => $b->account_id, 'kind' => 'scheduled', 'business_key' => hash('sha256', $id), 'digest' => hash('sha256', $b->id), 'state' => 'pending']);
                    DB::afterCommit(fn () => ProcessTracking::dispatch($b->merchant_id, $id));
                    $count++;
                }
                DB::table('tracking_scan_cursors')->where('name', $name)->update(['cursor' => $rows->count() === 100 ? $rows->last()->id : null]);
            }

            return $count;
        });
    }

    private function backfill(object $observation): void
    {
        app(TrackingService::class)->bind($observation->merchant_id, $observation->id);
    }
}
