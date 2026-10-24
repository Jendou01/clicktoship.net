<?php

namespace App\Modules\Customs\Application;

use App\Models\User;
use App\Modules\Customs\Domain\Countries;
use App\Modules\Customs\Domain\CustomsInput;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Application\Sessions;
use App\Modules\Operations\Application\Administration as Access;
use App\Modules\Operations\Application\PlatformSecurity;
use App\Modules\Shipping\Domain\ShipmentInput;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Administration
{
    public function target(Request $r, string $merchant, string $permission = 'platform.customs.view'): void
    {
        PlatformSecurity::lock();
        app(Sessions::class)->validate($r, User::findOrFail($r->user()->id));
        app(Access::class)->authorizeTarget($r, $merchant, $permission);
    }

    public function rules(Request $r): array
    {
        return DB::transaction(function () use ($r) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.customs.rules.manage');

            return ['rules' => DB::table('customs_country_rules')->orderBy('country')->get(), 'versions' => DB::table('customs_rule_versions')->orderByDesc('created_at')->limit(100)->get()->map(function ($v) {
                $v->proposal = json_decode(Crypt::decryptString($v->payload_ciphertext), true);
                unset($v->payload_ciphertext);

                return $v;
            }), 'externally_verified' => false, 'activation_enabled' => false];
        });
    }

    public function propose(Request $r): array
    {
        $d = $r->validate(['country' => 'required|string|size:2', 'version' => 'required|integer|min:0', 'proposal' => 'required|array:required_fields,incoterms,flags,evidence', 'proposal.required_fields' => 'present|array|list|max:20', 'proposal.required_fields.*' => 'required|string|regex:/^[a-z][a-z0-9_]{0,49}$/|distinct', 'proposal.incoterms' => 'present|array|list|max:11', 'proposal.incoterms.*' => 'required|distinct|in:'.implode(',', CustomsInput::TERMS), 'proposal.flags' => 'present|array|list|max:9', 'proposal.flags.*' => 'required|distinct|in:'.implode(',', CustomsInput::FLAGS), 'proposal.evidence' => 'required|string|min:20|max:2000', 'idempotency_key' => 'required|string|min:8|max:128', 'verified' => 'prohibited', 'enabled' => 'prohibited']);
        abort_unless(Countries::valid($d['country']), 422);

        return DB::transaction(function () use ($r, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.customs.rules.manage');
            $hash = ShipmentInput::digest([$d['country'], $d['version'], $d['proposal']]);
            $key = hash('sha256', $d['idempotency_key']);
            if ($old = DB::table('customs_rule_versions')->where('actor_id', $r->user()->id)->where('key_hash', $key)->first()) {
                abort_unless(hash_equals($old->digest, $hash), 409);

                return ['country' => $old->country, 'version' => $old->version];
            }
            $old = DB::table('customs_country_rules')->where('country', $d['country'])->lockForUpdate()->first();
            abort_unless(($old->version ?? 0) === $d['version'], 409);
            $v = $d['version'] + 1;
            if ($old) {
                DB::table('customs_country_rules')->where('country', $d['country'])->update(['version' => $v]);
            } else {
                DB::table('customs_country_rules')->insert(['country' => $d['country'], 'version' => $v]);
            }DB::table('customs_rule_versions')->insert(['country' => $d['country'], 'version' => $v, 'payload_ciphertext' => Crypt::encryptString(json_encode($d['proposal'])), 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'auth_version' => $r->user()->auth_version, 'key_hash' => $key, 'digest' => $hash]);
            Audit::record('customs.rule_proposed', $r->user()->id);

            return ['country' => $d['country'], 'version' => $v, 'externally_verified' => false];
        });
    }

    public function approve(Request $r, string $country): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'idempotency_key' => 'required|string|min:8|max:128']);

        return DB::transaction(function () use ($r, $country, $d) {
            PlatformSecurity::lock();
            PlatformSecurity::require($r, 'platform.customs.rules.approve');
            $root = DB::table('customs_country_rules')->where('country', $country)->lockForUpdate()->first();
            abort_unless($root, 404);
            $v = DB::table('customs_rule_versions')->where('country', $country)->where('version', $d['version'])->first();
            abort_unless($v, 404);
            abort_unless($v->actor_id !== $r->user()->id, 403);
            $maker = Request::create('/internal/customs-rules', 'POST');
            $maker->setUserResolver(fn () => User::findOrFail($v->actor_id));
            $session = new Store('customs-rule', new ArraySessionHandler(120));
            $session->put('auth_session_id', $v->session_id);
            $maker->setLaravelSession($session);
            abort_unless(DB::table('auth_sessions')->where('id', $v->session_id)->where('user_id', $v->actor_id)->where('auth_version', $v->auth_version)->whereNull('revoked_at')->where('expires_at', '>', now())->where('last_seen_at', '>', now()->subMinutes(config('session.lifetime')))->exists(), 403);
            abort_unless($maker->user()->auth_version === $v->auth_version, 403);
            PlatformSecurity::require($maker, 'platform.customs.rules.manage');
            $key = hash('sha256', $d['idempotency_key']);
            if ($old = DB::table('customs_rule_reviews')->where('actor_id', $r->user()->id)->where('key_hash', $key)->first()) {
                abort_unless($old->country === $country && $old->version === $d['version'], 409);

                return ['country' => $country, 'version' => $d['version'], 'externally_verified' => false];
            }
            abort_unless($root->version === $d['version'], 409);
            abort_if(DB::table('customs_rule_reviews')->where('country', $country)->where('version', $d['version'])->exists(), 409);
            DB::table('customs_rule_reviews')->insert(['country' => $country, 'version' => $d['version'], 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'key_hash' => $key]);
            DB::table('customs_country_rules')->where('country', $country)->update(['active_version' => $d['version']]);
            Audit::record('customs.rule_reviewed', $r->user()->id);

            return ['country' => $country, 'version' => $d['version'], 'externally_verified' => false, 'enabled' => false];
        });
    }

    public function diagnostics(Request $r, string $merchant): array
    {
        return DB::transaction(function () use ($r, $merchant) {
            $this->target($r, $merchant);

            return ['declarations' => DB::table('customs_declarations')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(), 'readiness' => DB::table('customs_readiness')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'shipment_id', 'revision', 'report', 'created_at']), 'documents' => DB::table('customs_documents as d')->leftJoin('customs_document_results as s', 's.document_id', '=', 'd.id')->where('d.merchant_id', $merchant)->orderByDesc('d.created_at')->limit(100)->get(['d.id', 'd.kind', 'd.number', 'd.version', 'd.shipment_id', 's.state']), 'reviews' => DB::table('customs_reviews')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'declaration_id', 'version', 'created_at']), 'events' => DB::table('customs_events')->where('merchant_id', $merchant)->orderByDesc('created_at')->limit(100)->get(['id', 'shipment_id', 'kind', 'created_at']), 'clearance_enabled' => false];
        });
    }

    public function reviewData(Request $r, string $merchant, string $id): array
    {
        return DB::transaction(function () use ($r, $merchant, $id) {
            $this->target($r, $merchant, 'platform.customs.review');
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $d = DB::table('customs_declarations')->where('merchant_id', $merchant)->where('id', $id)->first();
            abort_unless($d, 404);
            app(Declarations::class)->event($merchant, $d->shipment_id, 'private_review_opened', $r->user()->id);

            return app(Declarations::class)->show($merchant, $d->shipment_id);
        });
    }

    public function review(Request $r, string $merchant, string $id): array
    {
        $d = $r->validate(['version' => 'required|integer|min:1', 'note' => 'required|string|min:10|max:1000', 'idempotency_key' => 'required|string|min:8|max:128', 'approved' => 'prohibited', 'status' => 'prohibited']);

        return DB::transaction(function () use ($r, $merchant, $id, $d) {
            $this->target($r, $merchant, 'platform.customs.review');
            DB::table('merchants')->where('id', $merchant)->lockForUpdate()->firstOrFail();
            $v = DB::table('customs_versions')->where('merchant_id', $merchant)->where('declaration_id', $id)->where('version', $d['version'])->first();
            abort_unless($v, 404);
            $key = hash('sha256', $d['idempotency_key']);
            $hash = ShipmentInput::digest([$id, $d['version'], $d['note']]);
            $old = DB::table('customs_commands')->where('merchant_id', $merchant)->where('actor_id', $r->user()->id)->where('operation', 'review')->where('key_hash', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->digest, $hash), 409);

                return json_decode(Crypt::decryptString($old->result_ciphertext), true);
            }$review = (string) Str::uuid();
            DB::table('customs_reviews')->insert(['id' => $review, 'merchant_id' => $merchant, 'declaration_id' => $id, 'version' => $d['version'], 'actor_id' => $r->user()->id, 'session_id' => $r->session()->get('auth_session_id'), 'grant_id' => $r->input('access_grant'), 'note_ciphertext' => Crypt::encryptString($d['note'])]);
            $result = ['id' => $review, 'effect' => 'annotation_only', 'legal_approval' => false];
            DB::table('customs_commands')->insert(['merchant_id' => $merchant, 'actor_id' => $r->user()->id, 'operation' => 'review', 'key_hash' => $key, 'digest' => $hash, 'result_ciphertext' => Crypt::encryptString(json_encode($result))]);
            app(Declarations::class)->event($merchant, $v->shipment_id, 'compliance_reviewed', $r->user()->id);

            return $result;
        });
    }
}
