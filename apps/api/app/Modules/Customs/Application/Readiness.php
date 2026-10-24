<?php

namespace App\Modules\Customs\Application;

use App\Modules\Customs\Domain\Countries;
use App\Modules\Customs\Domain\CustomsInput;
use App\Modules\Shipping\Application\DraftReadiness;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class Readiness
{
    public function report(array $draft): array
    {
        $findings = [];
        $add = function ($field, $classification, $code) use (&$findings) {
            $findings[] = ['field' => $field, 'classification' => $classification, 'code' => $code];
        };
        foreach (app(DraftReadiness::class)->report($draft)['missing'] as $field) {
            if (! str_starts_with($field, 'customs.')) {
                $add($field, 'missing_information', 'FIELD_REQUIRED');
            }
        }
        $international = $draft['origin']['country'] !== $draft['destination']['country'];
        foreach (['origin', 'destination'] as $side) {
            if (! Countries::valid($draft[$side]['country'])) {
                $add($side.'.country', 'unsupported', 'ISO_COUNTRY_REQUIRED');
            }
        }
        $c = $draft['customs'] ?? null;
        if (! $c) {
            $add('customs', 'missing_information', 'DECLARATION_REQUIRED');
        } else {
            foreach (CustomsInput::local($c, $draft) as $field) {
                $add('customs.'.$field, 'missing_information', 'FIELD_REQUIRED');
            }
            foreach ($c['items'] as $i => $item) {
                if (empty($item['hs_code'])) {
                    $add("customs.items.$i.hs_code", 'requires_merchant_review', 'HS_APPLICABILITY_UNVERIFIED');
                }
                if (! empty($item['hs_code'])) {
                    $add("customs.items.$i.hs_code", 'requires_external_verification', 'MERCHANT_HS_NOT_CLASSIFIED');
                }
            }
            foreach ($c['categories'] ?? [] as $flag) {
                $add('customs.categories.'.$flag, 'requires_merchant_review', 'RESTRICTED_CATEGORY_REVIEW');
            }
            if (($c['incoterm'] ?? '') === 'DDP') {
                $add('customs.incoterm', 'requires_external_verification', 'DDP_CONTRACT_UNVERIFIED');
            }
        }
        $rule = Countries::valid($draft['destination']['country']) ? $this->country($draft['destination']['country']) : ['country' => $draft['destination']['country'], 'version' => null, 'required_fields' => [], 'incoterms' => [], 'informational_flags' => [], 'externally_verified' => false, 'enabled' => false];
        foreach ($rule['informational_flags'] as $flag) {
            $add('customs.categories.'.$flag, 'requires_merchant_review', 'REVIEWED_INFORMATIONAL_FLAG');
        }
        if ($rule['informational_flags'] && empty($c['restricted_acknowledgement'])) {
            $add('customs.restricted_acknowledgement', 'missing_information', 'FIELD_REQUIRED');
        }
        foreach ($rule['required_fields'] as $field) {
            if (empty($c['country_fields'][$field])) {
                $add('customs.country_fields.'.$field, 'missing_information', 'COUNTRY_FIELD_REQUIRED');
            }
        }
        if ($c && $rule['incoterms'] && ! in_array($c['incoterm'] ?? '', $rule['incoterms'], true)) {
            $add('customs.incoterm', 'unsupported', 'TERM_NOT_IN_REVIEWED_RULE');
        }
        if (! $international) {
            $add('route', 'unsupported', 'DOMESTIC_USE_STANDARD_SHIPPING');
        }
        $add('country_requirements', 'requires_external_verification', 'COUNTRY_RULE_UNVERIFIED');
        $add('service_id', 'requires_external_verification', 'CARRIER_CUSTOMS_CONTRACT_UNVERIFIED');
        $add('documents', 'requires_external_verification', 'DOCUMENT_POLICY_UNVERIFIED');
        $add('shipping', 'blocked', 'LIVE_INTERNATIONAL_DISABLED');
        $local = count(array_filter($findings, fn ($f) => in_array($f['classification'], ['missing_information', 'unsupported'], true))) === 0;
        if ($local) {
            $add('structured_data', 'validated', 'LOCAL_DATA_ONLY');
        }

        return ['international' => $international, 'local_valid' => $local, 'customs_cleared' => false, 'shipping_enabled' => false, 'country_rule' => $rule, 'findings' => $findings, 'document_generation_eligible' => $local, 'duties_calculated' => false, 'legal_approval' => false];
    }

    public function country(string $country): array
    {
        abort_unless(Countries::valid($country), 422);
        $r = DB::table('customs_country_rules')->where('country', $country)->first();
        $v = $r?->active_version ? DB::table('customs_rule_versions')->where('country', $country)->where('version', $r->active_version)->first() : null;
        $data = $v ? json_decode(Crypt::decryptString($v->payload_ciphertext), true) : [];

        return ['country' => $country, 'version' => $v?->version, 'required_fields' => $data['required_fields'] ?? [], 'incoterms' => $data['incoterms'] ?? [], 'informational_flags' => $data['flags'] ?? [], 'externally_verified' => false, 'enabled' => false, 'state' => $v ? 'reviewed_unverified' : 'requirements_unverified'];
    }
}
