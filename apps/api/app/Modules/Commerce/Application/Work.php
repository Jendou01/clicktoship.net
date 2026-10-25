<?php

namespace App\Modules\Commerce\Application;

use App\Modules\Commerce\Domain\IntegrationFailure;
use App\Modules\Commerce\Domain\StoreProvider;
use App\Modules\Commerce\Jobs\ProcessStore;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Pricing\Application\Catalog;
use App\Modules\Shipping\Application\ShippingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class Work
{
    public function queue(Request $r, MerchantContext $c, string $id, string $kind): array
    {
        $d = $kind === 'prepare' ? $r->validate(['selections' => 'required|array|min:1|max:25', 'selections.*' => 'required|array:order_id,snapshot_id,version,review,acknowledge_changes,preview_hash', 'selections.*.order_id' => 'required|uuid|distinct', 'selections.*.snapshot_id' => 'required|uuid', 'selections.*.version' => 'required|integer|min:1', 'selections.*.review' => 'required|array', 'selections.*.preview_hash' => 'required|string|size:64']) : [];

        return app(Access::class)->locked($r, $c, $kind === 'sync' ? 'integrations.sync' : 'orders.prepare', function ($m) use ($r, $c, $id, $kind, $d) {
            $conn = app(Access::class)->connection($m, $id);
            app(Access::class)->active($conn);
            if ($kind === 'sync') {
                abort_unless(app(StoreProvider::class)->available(), 503);
            } else {
                $c->require('orders.read');
                $c->require('shipments.write');
                foreach ($d['selections'] as $row) {
                    abort_unless(app(Orders::class)->row($m, $row['order_id'])->connection_id === $id, 404);
                }
            }

            return app(Access::class)->command($r, $m, $c->actorId(), 'queue:'.$kind.':'.$id, $d, function () use ($r, $c, $id, $m, $kind, $d, $conn) {
                abort_if(DB::table('commerce_runs')->where('connection_id', $id)->where('kind', $kind)->whereIn('state', ['queued', 'running', 'waiting', 'limited'])->exists(), 409);
                $run = (string) Str::uuid();
                DB::table('commerce_runs')->insert(['id' => $run, 'merchant_id' => $m, 'connection_id' => $id, 'generation' => $conn->generation, 'kind' => $kind, 'payload' => $d ? Crypt::encryptString(json_encode($d['selections'])) : null, 'checkpoint' => $conn->checkpoint] + app(Access::class)->origin($r, $c));
                app(Access::class)->event($m, $id, $kind.'_queued', $c->actorId(), ['run_id' => $run]);
                DB::afterCommit(fn () => ProcessStore::dispatch($m, $run));

                return ['run_id' => $run, 'state' => 'queued'];
            });
        });
    }

    private function locked(string $m, string $id, ?string $lease, callable $fn): mixed
    {
        return DB::transaction(function () use ($m, $id, $lease, $fn) {
            $first = DB::table('commerce_runs')->where('merchant_id', $m)->where('id', $id)->first();
            if (! $first) {
                return null;
            }if ($first->kind === 'prepare') {
                Catalog::lock(true);
            }DB::table('merchants')->where('id', $m)->lockForUpdate()->firstOrFail();
            $run = DB::table('commerce_runs')->where('merchant_id', $m)->where('id', $id)->lockForUpdate()->first();
            if (! in_array($run->state, ['queued', 'running', 'waiting']) || ($lease !== null && $run->lease_token !== $lease)) {
                return null;
            }app(ShippingAccess::class)->worker($run, $run->kind === 'sync' ? 'integrations.sync' : 'orders.prepare');
            if ($run->kind === 'prepare') {
                app(ShippingAccess::class)->worker($run, 'orders.read');
                app(ShippingAccess::class)->worker($run, 'shipments.write');
            }$conn = app(Access::class)->connection($m, $run->connection_id);
            abort_unless($conn->state === 'authorized' && $conn->generation === $run->generation, 403);

            return $fn($run, $conn);
        });
    }

    public function tick(string $m, string $id): bool
    {
        app(MerchantContext::class)->clear();
        $lease = (string) Str::uuid();
        try {
            $run = $this->locked($m, $id, null, function ($w, $c) use ($lease) {
                if (now()->lt($w->next_at) || ($w->lease_until && now()->lt($w->lease_until))) {
                    return null;
                }DB::table('commerce_runs')->where('id', $w->id)->update(['state' => 'running', 'lease_token' => $lease, 'lease_until' => now()->addSeconds(60), 'attempt' => $w->attempt + 1, 'updated_at' => now()]);

                return $w;
            });
            if (! $run) {
                return false;
            }
            if ($run->attempt >= 5) {
                throw new IntegrationFailure('RECOVERY_LIMIT');
            }
            if ($run->kind === 'prepare') {
                $this->prepare($m, $id, $lease);
            } else {
                $credential = app(Tokens::class)->credential($run);
                $page = app(StoreProvider::class)->orders($credential, $run->checkpoint ? Crypt::decryptString($run->checkpoint) : null, $run->cursor ? Crypt::decryptString($run->cursor) : null);
                $this->locked($m, $id, $lease, function ($w, $c) use ($page) {
                    if (! is_array($page['orders'] ?? null) || count($page['orders']) > config('commerce.max_page_orders') || ($page['store_id'] ?? null) !== $c->external_store_id || ($page['provenance'] ?? null) !== $c->provenance || ! array_key_exists('next_cursor', $page) || ! array_key_exists('checkpoint', $page) || strlen(json_encode($page)) > config('commerce.max_payload_bytes')) {
                        throw new IntegrationFailure('API_INVALID_PAYLOAD');
                    }foreach (['next_cursor', 'checkpoint'] as $key) {
                        if ($page[$key] !== null && (! is_string($page[$key]) || strlen($page[$key]) > 2048)) {
                            throw new IntegrationFailure('API_INVALID_CURSOR');
                        }
                    }
                    $cursor = $w->cursor ? Crypt::decryptString($w->cursor) : '';
                    $hash = hash('sha256', $cursor);
                    if (DB::table('commerce_pages')->where('run_id', $w->id)->where('cursor_hash', $hash)->exists()) {
                        throw new IntegrationFailure('PAGINATION_CYCLE');
                    }if ($w->position + count($page['orders']) > config('commerce.max_total_orders')) {
                        throw new IntegrationFailure('IMPORT_LIMIT');
                    }
                    foreach ($page['orders'] as $order) {
                        app(Orders::class)->ingest($c, $order);
                    }DB::table('commerce_pages')->insert(['merchant_id' => $w->merchant_id, 'run_id' => $w->id, 'cursor_hash' => $hash]);
                    $state = $page['next_cursor'] === null ? 'succeeded' : ($w->pages + 1 >= config('commerce.max_pages') ? 'limited' : 'queued');
                    DB::table('commerce_runs')->where('id', $w->id)->update(['state' => $state, 'cursor' => $page['next_cursor'] === null ? null : Crypt::encryptString($page['next_cursor']), 'pages' => $w->pages + 1, 'position' => $w->position + count($page['orders']), 'attempt' => 0, 'lease_until' => null, 'lease_token' => null, 'failure_code' => $state === 'limited' ? 'IMPORT_LIMIT' : null, 'updated_at' => now()]);
                    if ($state === 'succeeded') {
                        DB::table('commerce_connections')->where('id', $c->id)->update(['checkpoint' => $page['checkpoint'] === null ? null : Crypt::encryptString($page['checkpoint']), 'last_synced_at' => now(), 'error_code' => null, 'version' => DB::raw('version+1')]);
                    }app(Access::class)->event($w->merchant_id, $c->id, 'sync_page_committed', $w->actor_id, ['run_id' => $w->id, 'count' => count($page['orders']), 'state' => $state]);
                });
            }
        } catch (IntegrationFailure $e) {
            $this->fail($m, $id, $lease, $e->reason, $e->retryable, $e->retryAfter);
        } catch (ValidationException) {
            $this->fail($m, $id, $lease, 'API_INVALID_PAYLOAD');
        } catch (HttpException) {
            $this->fail($m, $id, $lease, 'AUTHORITY_OR_CONNECTION_CHANGED');
        } finally {
            app(MerchantContext::class)->clear();
        }

        return DB::table('commerce_runs')->where('merchant_id', $m)->where('id', $id)->where('state', 'queued')->exists();
    }

    private function prepare(string $m, string $id, string $lease): void
    {
        $this->locked($m, $id, $lease, function ($w, $c) {
            $rows = json_decode(Crypt::decryptString($w->payload), true);
            $d = $rows[$w->position] ?? null;
            if (! $d) {
                return;
            }$order = $d['order_id'];
            $result = null;
            try {
                $result = DB::transaction(fn () => app(Drafts::class)->persist($w, $order, $d));
            } catch (HttpException|ValidationException) {
                $result = ['state' => 'failed', 'shipment_id' => null, 'error_code' => 'DRAFT_REVIEW_REQUIRED'];
            }
            DB::table('commerce_results')->insert(['merchant_id' => $w->merchant_id, 'run_id' => $w->id, 'order_id' => $order, 'state' => $result['state'], 'shipment_id' => $result['shipment_id'], 'error_code' => $result['error_code'] ?? null]);
            $next = $w->position + 1;
            DB::table('commerce_runs')->where('id', $w->id)->update(['position' => $next, 'state' => $next >= count($rows) ? 'succeeded' : 'queued', 'lease_token' => null, 'lease_until' => null, 'attempt' => 0, 'updated_at' => now()]);
            app(Access::class)->event($w->merchant_id, $c->id, 'draft_batch_progress', $w->actor_id, ['run_id' => $w->id, 'position' => $next, 'result' => $result['state']]);
        });
    }

    private function fail(string $m, string $id, string $lease, string $code, bool $retry = false, int $delay = 30): void
    {
        DB::transaction(function () use ($m, $id, $lease, $code, $retry, $delay) {
            DB::table('merchants')->where('id', $m)->lockForUpdate()->first();
            $w = DB::table('commerce_runs')->where('merchant_id', $m)->where('id', $id)->whereIn('state', ['queued', 'running', 'waiting'])->lockForUpdate()->first();
            if (! $w || ($w->lease_token && $w->lease_token !== $lease)) {
                return;
            }$state = $retry && $w->attempt < 5 ? 'waiting' : ($code === 'AUTHORITY_OR_CONNECTION_CHANGED' ? 'blocked' : 'failed');
            DB::table('commerce_runs')->where('id', $id)->update(['state' => $state, 'failure_code' => $code, 'next_at' => now()->addSeconds(max(1, min(900, $delay))), 'lease_until' => null, 'lease_token' => null, 'updated_at' => now()]);
            DB::table('commerce_connections')->where('id', $w->connection_id)->update(['error_code' => $code]);
            app(Access::class)->event($m, $w->connection_id, 'work_'.$state, $w->actor_id, ['run_id' => $id, 'code' => $code]);
        });
    }

    public function recover(): int
    {
        $q = DB::table('commerce_runs')->whereIn('state', ['queued', 'running', 'waiting'])->where('next_at', '<=', now())->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))->orderBy('next_at')->limit(100)->get(['id', 'merchant_id']);
        foreach ($q as $w) {
            ProcessStore::dispatch($w->merchant_id, $w->id);
        }

        return $q->count();
    }

    public function retry(Request $r, MerchantContext $c, string $id): array
    {
        $d = $r->validate(['expected_position' => 'required|integer|min:0|max:1000']);

        return app(Access::class)->locked($r, $c, 'integrations.sync', function ($m) use ($id, $c, $r, $d) {
            return app(Access::class)->command($r, $m, $c->actorId(), 'retry:'.$id, $d, function () use ($id, $c, $r, $d, $m) {
                $w = DB::table('commerce_runs')->where('merchant_id', $m)->where('id', $id)->first();
                abort_unless($w && $w->kind === 'sync', 404);
                $conn = app(Access::class)->connection($m, $w->connection_id);
                app(Access::class)->active($conn);
                abort_unless($w->generation === $conn->generation && in_array($w->state, ['waiting', 'limited']) && $w->position === $d['expected_position'], 409);
                abort_if($w->state === 'waiting' && now()->lt($w->next_at), 409);
                abort_if($w->position >= config('commerce.max_total_orders'), 409);
                DB::table('commerce_runs')->where('id', $id)->update(['state' => 'queued', 'pages' => 0, 'next_at' => now(), 'failure_code' => null] + app(Access::class)->origin($r, $c));
                app(Access::class)->event($m, $conn->id, 'sync_resumed', $c->actorId(), ['run_id' => $id, 'position' => $w->position]);
                DB::afterCommit(fn () => ProcessStore::dispatch($m, $id));

                return ['run_id' => $id, 'state' => 'queued'];
            });
        });
    }
}
