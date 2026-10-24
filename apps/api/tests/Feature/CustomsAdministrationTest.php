<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\CustomsTestCase;

final class CustomsAdministrationTest extends CustomsTestCase
{
    use DatabaseMigrations;

    public function test_country_rules_need_independent_fresh_mfa_review_but_never_enable_external_approval(): void
    {
        $f = $this->customsFixture();
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->adminLogin($a);
        $d = ['country' => 'AE', 'version' => 0, 'proposal' => ['required_fields' => ['importer_reference'], 'incoterms' => ['DAP'], 'flags' => ['batteries'], 'evidence' => 'Synthetic proposed policy evidence; not a real customs rule'], 'idempotency_key' => 'country-rule-new'];
        $this->browser('POST', '/api/v1/admin/customs/rules', $d)->assertOk()->assertJsonPath('data.version', 1);
        $this->browser('POST', '/api/v1/admin/customs/rules', $d)->assertOk();
        $this->browser('POST', '/api/v1/admin/customs/rules/AE/review', ['version' => 1, 'idempotency_key' => 'rule-review-first'])->assertForbidden();
        $this->adminLogin($b);
        $this->browser('POST', '/api/v1/admin/customs/rules/AE/review', ['version' => 1, 'idempotency_key' => 'rule-review-first'])->assertOk()->assertJsonPath('data.enabled', false);
        $this->bearer($f['token'], 'GET', '/api/v1/customs/countries/AE')->assertOk()->assertJsonPath('data.state', 'reviewed_unverified')->assertJsonPath('data.externally_verified', false);
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $this->assertContains('customs.country_fields.importer_reference', array_column($s['readiness']['findings'], 'field'));
        $this->assertFalse($s['readiness']['customs_cleared']);
    }

    public function test_target_reviews_require_independent_grant_and_do_not_approve_customs(): void
    {
        $f = $this->customsFixture();
        $s = $this->saveCustoms($f)->assertOk()->json('data');
        $a = $this->platformUser();
        $b = $this->platformUser();
        $this->login($a);
        $this->browser('GET', '/api/v1/admin/customs/rules')->assertForbidden();
        $g = $this->grant($a, $b, $f['m'], ['platform.customs.view', 'platform.customs.review']);
        $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/customs?access_grant=$g")->assertOk();
        $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/customs/declarations/{$s['declaration']['id']}?access_grant=$g")->assertOk()->assertJsonPath('data.customs.items.0.description_ar', 'بضاعة اختبار اصطناعية')->assertHeader('Cache-Control', 'no-store, private');
        $other = $this->organization($this->user());
        $this->browser('GET', "/api/v1/admin/merchants/$other/customs/declarations/{$s['declaration']['id']}?access_grant=$g")->assertForbidden();
        $path = "/api/v1/admin/merchants/{$f['m']}/customs/declarations/{$s['declaration']['id']}/reviews";
        $body = ['version' => 1, 'note' => 'Synthetic restricted item/document review only', 'access_grant' => $g, 'idempotency_key' => 'annotate-customs-review'];
        $id = $this->browser('POST', $path, $body)->assertCreated()->assertJsonPath('data.legal_approval', false)->json('data.id');
        $this->browser('POST', $path, $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->browser('POST', $path, $body + ['approved' => true])->assertUnprocessable();
        DB::table('platform_access_grants')->where('id', $g)->update(['revoked_at' => now()]);
        $this->browser('GET', "/api/v1/admin/merchants/{$f['m']}/customs?access_grant=$g")->assertForbidden();
        $this->assertDatabaseCount('customs_reviews', 1);
    }
}
