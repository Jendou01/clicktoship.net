<?php

namespace App\Modules\Carriers\Application;

use App\Models\User;
use App\Modules\Carriers\Domain\CarrierCapabilityRegistry;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\PlatformSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CarrierAdministration
{
    public function transaction(\Closure $work): mixed
    {
        try {
            return DB::transaction(function () use ($work) {
                PlatformSecurity::lock();
                DB::select('SELECT pg_advisory_xact_lock(6062026)');

                return $work();
            });
        } catch (\PDOException $e) {
            abort(in_array((string) $e->getCode(), ['23505', '23514', '23503'], true) ? 409 : 503);
        }
    }

    public function authorize(Request $r, string $permission): void
    {
        $u = User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
        abort_if($u->suspended_at || ! $u->hasVerifiedEmail(), 403);
        app(Sessions::class)->validate($r, $u);
        PlatformSecurity::require($r, $permission);
    }

    public function index(Request $r): array
    {
        return $this->transaction(function () use ($r) {
            $this->authorize($r, 'platform.carriers.view');

            return ['accounts' => DB::table('carrier_accounts as a')->join('carrier_account_versions as v', function ($j) {
                $j->on('a.id', '=', 'v.account_id')->on('a.version', '=', 'v.version');
            })->orderBy('a.created_at')->limit(100)->get(['a.id', 'a.code', 'a.carrier', 'a.environment', 'a.version', 'a.state_version', 'a.active_version', 'v.name_en', 'v.name_ar', 'v.evidence', 'v.services', 'v.created_by'])->map(function ($a) {
                $a->services = json_decode($a->services, true);
                $a->external_enabled = false;
                $a->sandbox_verified = false;
                $a->credentials_configured = DB::table('carrier_account_versions')->where('account_id', $a->id)->where('version', $a->version)->whereNotNull('credentials_ciphertext')->exists();

                return $a;
            }), 'capabilities' => app(CarrierCapabilityRegistry::class)->all(), 'webhook' => ['enabled' => false, 'reason' => 'AUTHENTICITY_SPEC_MISSING'], 'production_enabled' => false];
        });
    }

    public function write(Request $r, ?string $account = null): array
    {
        $d = $r->validate(['code' => 'required|regex:/^[a-zA-Z0-9_-]{1,80}$/', 'environment' => 'required|in:sandbox,production', 'version' => 'required|integer|min:0', 'name_en' => 'required|string|max:100', 'name_ar' => 'required|string|max:100', 'evidence' => 'required|string|min:10|max:1000', 'credentials' => 'nullable|array:UserName,Password,Version,AccountNumber,AccountPin,AccountEntity,AccountCountryCode,Source', 'clear_credentials' => 'sometimes|boolean', 'services' => 'present|array|max:100', 'merchant_id' => 'prohibited', 'enabled' => 'prohibited', 'endpoint' => 'prohibited', 'sandbox_verified' => 'prohibited']);
        abort_if(array_diff(array_keys($r->all()), array_keys($d)) !== [], 422);
        abort_if(($d['clear_credentials'] ?? false) && ! empty($d['credentials']), 422);
        $services = app(CarrierServiceMapper::class)->validate($d['services']);

        return $this->transaction(function () use ($r, $account, $d, $services) {
            $this->authorize($r, 'platform.carriers.manage');
            $old = $account ? DB::table('carrier_accounts')->where('id', $account)->lockForUpdate()->first() : null;
            abort_if($account && ! $old, 404);
            abort_unless($d['version'] === ($old->version ?? 0), 409);
            abort_if($old && ($old->code !== $d['code'] || $old->environment !== $d['environment']), 422);
            $id = $old->id ?? (string) Str::uuid();
            $version = $d['version'] + 1;
            $credentials = app(CarrierCredentialsManager::class)->encrypt($d['credentials'] ?? null);
            if (! $credentials && ! ($d['clear_credentials'] ?? false) && $old) {
                $credentials = DB::table('carrier_account_versions')->where('account_id', $id)->where('version', $old->version)->value('credentials_ciphertext');
            }
            if (! $old) {
                DB::table('carrier_accounts')->insert(['id' => $id, 'carrier' => 'aramex', 'code' => $d['code'], 'environment' => $d['environment']]);
            } else {
                DB::table('carrier_accounts')->where('id', $id)->update(['version' => $version, 'state_version' => $old->state_version + 1]);
            }
            DB::table('carrier_account_versions')->insert(['account_id' => $id, 'version' => $version, 'name_en' => $d['name_en'], 'name_ar' => $d['name_ar'], 'evidence' => $d['evidence'], 'credentials_ciphertext' => $credentials, 'services' => json_encode($services), 'created_by' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id')]);
            $this->event($r, $id, $version, 'draft');

            return ['id' => $id, 'version' => $version, 'state_version' => ($old->state_version ?? 0) + 1, 'external_enabled' => false];
        });
    }

    public function change(Request $r, string $account, string $action): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'state_version' => 'required|integer|min:1']);

        return $this->transaction(function () use ($r, $account, $action, $d) {
            $this->authorize($r, 'platform.carriers.approve');
            $a = DB::table('carrier_accounts')->where('id', $account)->lockForUpdate()->first();
            abort_unless($a, 404);
            abort_unless($a->version === $d['version'] && $a->state_version === $d['state_version'], 409);
            $v = DB::table('carrier_account_versions')->where('account_id', $account)->where('version', $a->version)->first();
            abort_if($v->created_by === $r->user()->id, 403);
            $maker = User::whereKey($v->created_by)->lockForUpdate()->first();
            $s = DB::table('auth_sessions')->where('id', $v->session_id)->whereNull('revoked_at')->first();
            $m = DB::table('user_mfa_credentials')->where('user_id', $v->created_by)->whereNotNull('confirmed_at')->first();
            abort_unless($maker && ! $maker->suspended_at && $maker->hasVerifiedEmail() && $s && $m && $s->auth_version === $maker->auth_version && $s->mfa_version === $m->version && $s->mfa_verified_at && now()->subMinutes(15)->lt($s->mfa_verified_at) && now()->lt($s->expires_at) && now()->subMinutes(config('session.lifetime'))->lt($s->last_seen_at) && in_array('platform.carriers.manage', PlatformSecurity::permissions($maker->id), true), 403);
            DB::table('carrier_accounts')->where('id', $account)->update(['active_version' => $action === 'approve' ? $a->version : null, 'state_version' => $a->state_version + 1]);
            $this->event($r, $account, $a->version, $action);
            app(CarrierIntegrationHealth::class)->record($account, 'unverified', 'CONTRACT_UNVERIFIED');

            return ['id' => $account, 'version' => $a->version, 'state_version' => $a->state_version + 1, 'configuration_approved' => $action === 'approve', 'external_enabled' => false];
        });
    }

    public function history(Request $r, string $account): array
    {
        return $this->transaction(function () use ($r, $account) {
            $this->authorize($r, 'platform.carriers.view');
            abort_unless(DB::table('carrier_accounts')->where('id', $account)->exists(), 404);

            return ['events' => DB::table('carrier_config_events')->where('account_id', $account)->orderByDesc('created_at')->limit(100)->get(['id', 'version', 'actor_id', 'action', 'created_at']), 'health' => DB::table('carrier_health_records')->where('account_id', $account)->orderByDesc('created_at')->limit(100)->get(['id', 'status', 'reason', 'created_at']),
                'errors' => DB::table('carrier_attempts as a')->join('carrier_operations as o', 'o.id', '=', 'a.operation_id')->where('o.account_id', $account)->whereNotNull('a.error_code')->orderByDesc('a.created_at')->limit(100)->get(['a.correlation_id', 'a.outcome', 'a.error_code', 'a.http_status', 'a.created_at'])];
        });
    }

    private function event(Request $r, string $account, int $version, string $action): void
    {
        DB::table('carrier_config_events')->insert(['id' => (string) Str::uuid(), 'account_id' => $account, 'version' => $version, 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'action' => $action]);
    }
}
