<?php

namespace App\Modules\Carriers\Application;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Carriers\Domain\CarrierFailure;
use App\Modules\Merchants\Domain\MerchantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal-only scoped intents. Current evidence gate stops execution before transmission. */
final class CarrierOperations
{
    public function prepare(MerchantContext $context, string $account, string $operation, string $businessKey, array $input): array
    {
        $context->require('rates.quote');
        abort_unless(isset(CarrierCapabilityRegistry::PATHS[$operation]), 422);
        abort_unless(strlen($businessKey) >= 8 && strlen($businessKey) <= 128, 422);
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };
        $digest = hash('sha256', json_encode($sort($input), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($context, $account, $operation, $businessKey, $input, $digest) {
            DB::table('merchants')->where('id', $context->id())->lockForUpdate()->first();
            $u = User::whereKey($context->actorId())->lockForUpdate()->firstOrFail();
            $tokenId = $context->tokenId();
            $token = $tokenId ? PersonalAccessToken::find($tokenId) : null;
            abort_if($tokenId && ! $token, 403);
            $context->establish($u, $context->id(), $token);
            $context->require('rates.quote');
            $a = DB::table('carrier_accounts')->where('id', $account)->where('environment', 'sandbox')->whereNotNull('active_version')->first();
            abort_unless($a, 404);
            $profile = DB::table('carrier_account_versions')->where('account_id', $account)->where('version', $a->active_version)->first();
            $data = $input;
            $service = [];
            if (isset($input['origin'],$input['destination'])) {
                $service = app(CarrierServiceMapper::class)->resolve(json_decode($profile->services, true), $input['service_code'] ?? '', $input['origin']['country'] ?? '', $input['destination']['country'] ?? '');
                unset($data['service_code']);
            }
            if ($operation === 'CreatePickup') {
                $services = json_decode($profile->services, true);
                $matches = array_values(array_filter($services, fn ($s) => $s['code'] === ($input['service_code'] ?? '')));
                $service = $matches[0] ?? [];
                unset($data['service_code']);
            }
            app(CarrierRegistry::class)->get('aramex')->request($operation, $data, $service);
            abort_if(strlen(json_encode($input)) > 65536, 422);
            $old = DB::table('carrier_operations')->where('account_id', $account)->where('merchant_id', $context->id())->where('operation', $operation)->where('business_key', $businessKey)->first();
            if ($old) {
                abort_unless(hash_equals($old->digest, $digest), 409);

                return $this->present($old);
            }
            $id = (string) Str::uuid();
            DB::table('carrier_operations')->insert(['id' => $id, 'account_id' => $account, 'account_version' => $a->active_version, 'environment' => 'sandbox', 'merchant_id' => $context->id(), 'actor_id' => $context->actorId(), 'origin_token_id' => $context->tokenId(), 'operation' => $operation, 'business_key' => $businessKey, 'digest' => $digest, 'request_ciphertext' => Crypt::encryptString(json_encode($input)), 'correlation_id' => (string) Str::uuid(), 'state' => 'pending']);

            return $this->present(DB::table('carrier_operations')->where('id', $id)->first());
        });
    }

    public function execute(string $merchant, string $id): array
    {
        // Worker messages carry scope, never infer merchant from a globally supplied operation UUID.
        $op = DB::transaction(function () use ($merchant, $id) {
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->first();
            $op = DB::table('carrier_operations')->where('id', $id)->where('merchant_id', $merchant)->lockForUpdate()->first();
            abort_unless($op, 404);
            if ($op->state !== 'pending') {
                return $op;
            } // sending is treated as unresolved after a crash; never resubmit.
            $u = User::whereKey($op->actor_id)->lockForUpdate()->firstOrFail();
            $c = app(MerchantContext::class);
            $originatingToken = $op->origin_token_id;
            try {
                $token = $originatingToken ? PersonalAccessToken::find($originatingToken) : null;
                abort_if($originatingToken && ! $token, 403);
                $c->establish($u, $merchant, $token);
                $c->require('rates.quote');
            } finally {
                $c->clear();
            }
            try {
                app(CarrierCapabilityRegistry::class)->assertExternal($op->operation, $op->environment);
            } catch (CarrierFailure $e) {
                DB::table('carrier_operations')->where('id', $id)->update(['state' => 'blocked', 'error_code' => $e->errorCode, 'updated_at' => now()]);
                $this->attempt($op, 1, 'blocked', $e);
                app(CarrierIntegrationHealth::class)->record($op->account_id, 'blocked', $e->errorCode);

                return DB::table('carrier_operations')->where('id', $id)->first();
            }
        });

        // Phase 6 deliberately cannot pass this gate. A future verified contract must implement finalization here.
        return $this->present($op);
    }

    public function quarantineStale(string $merchant, string $id): array
    {
        return DB::transaction(function () use ($merchant, $id) {
            $op = DB::table('carrier_operations')->where('id', $id)->where('merchant_id', $merchant)->lockForUpdate()->first();
            abort_unless($op, 404);
            abort_unless($op->state === 'sending' && now()->subMinutes(5)->gt($op->updated_at), 409);
            DB::table('carrier_operations')->where('id', $id)->update(['state' => 'unknown', 'error_code' => 'WORKER_OUTCOME_UNKNOWN', 'updated_at' => now()]);
            $this->attempt($op, 2, 'unknown', new CarrierFailure('WORKER_OUTCOME_UNKNOWN', true));

            return ['id' => $id, 'state' => 'unknown', 'reconciliation_required' => true];
        });
    }

    private function attempt(object $op, int $number, string $outcome, CarrierFailure $e): void
    {
        DB::table('carrier_attempts')->insert(['id' => (string) Str::uuid(), 'operation_id' => $op->id, 'number' => $number, 'outcome' => $outcome, 'error_code' => $e->errorCode, 'http_status' => $e->httpStatus, 'correlation_id' => $op->correlation_id]);
    }

    public function present(object $op): array
    {
        return ['id' => $op->id, 'correlation_id' => $op->correlation_id, 'operation' => $op->operation, 'state' => $op->state, 'error_code' => $op->error_code, 'reconciliation_required' => in_array($op->state, ['unknown', 'sending'], true)];
    }
}
