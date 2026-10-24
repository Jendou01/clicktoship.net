<?php

namespace App\Modules\Tracking\Application;

use App\Modules\Tracking\Jobs\ProcessTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class TrackingWebhookProcessor
{
    public function receive(Request $r, string $endpoint): array
    {
        // Independent of merchant sessions/CSRF. No provider protocol is guessed.
        abort_unless(strtolower(trim(explode(';', (string) $r->header('Content-Type'))[0])) === 'application/json', 415);
        abort_if((int) $r->header('Content-Length', 0) > 65536, 413);
        $raw = stream_get_contents($r->getContent(true), 65537);
        abort_if(strlen($raw) > 65536, 413);
        $e = DB::table('tracking_endpoints')->where('id', $endpoint)->first();
        abort_unless($e && $e->enabled, 503);
        $registry = app(TrackingProviderRegistry::class);
        abort_unless($registry->allowed($e->account_id, 'webhook'), 503);
        try {
            $envelope = $registry->for($e->account_id)->authenticator()->verify($raw, $r->headers->all());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            abort(422);
        }
        Validator::make($envelope, ['delivery_id' => 'required|string|max:128', 'replay_id' => 'required|string|max:128', 'sent_at' => 'required|date|after_or_equal:'.now()->subMinutes(5)->toIso8601String().'|before_or_equal:'.now()->addSeconds(30)->toIso8601String(), 'reference' => 'required|string|max:128', 'events' => 'required|array|min:1|max:100'])->validate();
        abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $envelope['sent_at']), 422);
        try {
            $result = DB::transaction(function () use ($e, $raw, $envelope) {
                DB::table('merchants')->where('id', $e->merchant_id)->where('status', 'active')->lockForUpdate()->firstOrFail();
                $current = DB::table('tracking_endpoints')->where('id', $e->id)->lockForUpdate()->first();
                abort_unless($current->enabled && app(TrackingProviderRegistry::class)->allowed($e->account_id, 'webhook'), 503);
                $key = hash('sha256', $e->id.':'.$envelope['delivery_id']);
                $digest = hash('sha256', $raw);
                $old = DB::table('tracking_work')->where('endpoint_id', $e->id)->where('business_key', $key)->first();
                if ($old) {
                    abort_unless(hash_equals($old->digest, $digest), 409);

                    return ['receipt' => $old->id, 'duplicate' => true];
                }
                $replay = hash('sha256', $envelope['replay_id']);
                abort_if(DB::table('tracking_work')->where('endpoint_id', $e->id)->where('replay_hash', $replay)->exists(), 409);
                $b = DB::table('tracking_bindings')->where('merchant_id', $e->merchant_id)->where('account_id', $e->account_id)->where('reference_hash', TrackingService::referenceHash($envelope['reference']))->first();
                abort_if(DB::table('tracking_work')->where('endpoint_id', $e->id)->whereIn('state', ['pending', 'processing'])->count() >= 1000, 429);
                $id = (string) Str::uuid();
                DB::table('tracking_work')->insert(['id' => $id, 'merchant_id' => $e->merchant_id, 'binding_id' => $b?->id, 'account_id' => $e->account_id, 'endpoint_id' => $e->id, 'kind' => 'webhook', 'state' => $b ? 'pending' : 'quarantined', 'error_code' => $b ? null : 'REFERENCE_UNBOUND', 'business_key' => $key, 'digest' => $digest, 'replay_hash' => $replay, 'payload_ciphertext' => Crypt::encryptString(json_encode($envelope['events'], JSON_THROW_ON_ERROR))]);
                if ($b) {
                    DB::afterCommit(fn () => ProcessTracking::dispatch($e->merchant_id, $id));
                }

                return ['receipt' => $id, 'duplicate' => false];
            });
            $this->receipt($e, $result['receipt'], $result['duplicate'] ? 'duplicate' : 'accepted');

            return $result;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 409) {
                $this->receipt($e, null, 'replay_conflict');
            }throw $exception;
        }
    }

    private function receipt(object $e, ?string $work, string $outcome): void
    {
        DB::table('tracking_receipts')->insert(['id' => (string) Str::uuid(), 'merchant_id' => $e->merchant_id, 'endpoint_id' => $e->id, 'account_id' => $e->account_id, 'work_id' => $work, 'outcome' => $outcome]);
    }
}
