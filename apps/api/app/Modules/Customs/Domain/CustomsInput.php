<?php

namespace App\Modules\Customs\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CustomsInput
{
    public const TERMS = ['EXW', 'FCA', 'FAS', 'FOB', 'CFR', 'CIF', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public const FLAGS = ['dangerous_goods', 'batteries', 'liquids', 'food', 'cosmetics', 'medicines', 'chemicals', 'high_value', 'restricted_destination'];

    public static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function normalize(array $input, array $draft, bool $match = true): array
    {
        if (! array_is_list($draft['packages'])) {
            self::fail('packages', 'Packages must be an ordered list.');
        }
        $party = 'name,country,city,line1,postal,state,phone,email,business_id,tax_id';
        $rules = ['restricted_acknowledgement' => 'nullable|string|min:10|max:1000', 'purpose' => 'nullable|in:commercial,non_commercial,gift,sample,documents', 'exporter' => 'sometimes|array:'.$party, 'importer' => 'sometimes|array:'.$party, 'invoice_date' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'merchant_reference' => 'nullable|string|max:100', 'customs_reference' => 'nullable|string|max:100', 'instructions' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:1000', 'incoterm' => 'nullable|in:'.implode(',', self::TERMS), 'duty_payer' => 'nullable|in:sender,recipient,third_party,unverified', 'country_fields' => 'sometimes|array|max:20', 'country_fields.*' => 'nullable|string|max:200', 'categories' => 'sometimes|array|list|max:9', 'categories.*' => 'required|distinct|in:'.implode(',', self::FLAGS), 'items' => 'required|array|list|min:1|max:100', 'items.*' => 'required|array:description_en,description_ar,quantity,uom,unit_value_minor,currency,origin_country,hs_code,sku,material,intended_use,category,notes,net_grams,gross_grams,allocations', 'items.*.description_en' => 'required|string|max:200', 'items.*.description_ar' => 'required|string|max:200', 'items.*.quantity' => 'required|integer|min:1|max:100000', 'items.*.uom' => 'required|in:piece,set,box,kg', 'items.*.unit_value_minor' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})$/'], 'items.*.currency' => 'required|regex:/^[A-Z]{3}$/', 'items.*.origin_country' => 'required|regex:/^[A-Z]{2}$/', 'items.*.hs_code' => ['nullable', 'string', 'regex:/^[0-9]{6,12}$/'], 'items.*.sku' => 'nullable|string|max:100', 'items.*.material' => 'nullable|string|max:200', 'items.*.intended_use' => 'nullable|string|max:200', 'items.*.category' => 'nullable|string|max:100', 'items.*.notes' => 'nullable|string|max:500', 'items.*.net_grams' => 'nullable|integer|min:0|max:1000000000', 'items.*.gross_grams' => 'nullable|integer|min:0|max:1000000000', 'items.*.allocations' => 'present|array|list|max:100', 'items.*.allocations.*' => 'required|array:package,quantity,weight_grams', 'items.*.allocations.*.package' => 'required|integer|min:1|max:100', 'items.*.allocations.*.quantity' => 'required|integer|min:1|max:100000', 'items.*.allocations.*.weight_grams' => 'nullable|integer|min:0|max:1000000000'];
        foreach (['exporter', 'importer'] as $side) {
            foreach (explode(',', $party) as $field) {
                $rules[$side.'.'.$field] = $field === 'country' ? 'nullable|regex:/^[A-Z]{2}$/' : ($field === 'phone' ? 'nullable|regex:/^\+[1-9][0-9]{6,14}$/' : ($field === 'email' ? 'nullable|email|max:254' : 'nullable|string|max:200'));
            }
        }
        $d = Validator::make($input, $rules)->validate();
        if (array_diff(array_keys($input), array_keys($d))) {
            self::fail('customs', 'Unsupported declaration fields.');
        }
        foreach (['origin', 'destination'] as $side) {
            if (! Countries::valid($draft[$side]['country'])) {
                self::fail($side.'.country', 'Use a recognized ISO country code.');
            }
        }
        foreach (['exporter' => 'origin', 'importer' => 'destination'] as $side => $ship) {
            if (isset($d[$side]['country']) && (! Countries::valid($d[$side]['country']) || $d[$side]['country'] !== $draft[$ship]['country'])) {
                self::fail($side.'.country', 'Country must match the shipment revision.');
            }
        }
        foreach (array_keys($d['country_fields'] ?? []) as $key) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key)) {
                self::fail('country_fields', 'Invalid country field identifier.');
            }
        }
        $currency = DB::table('financial_currencies')->where('code', $draft['currency'])->where('enabled', true)->first();
        if (! $currency) {
            self::fail('currency', 'Currency is not enabled.');
        }
        $total = '0';
        $allocated = [];
        $gross = 0;
        foreach ($d['items'] as $i => &$item) {
            if ($item['currency'] !== $draft['currency']) {
                self::fail("items.$i.currency", 'Implicit currency conversion is prohibited.');
            }
            if (! Countries::valid($item['origin_country'])) {
                self::fail("items.$i.origin_country", 'Use a recognized ISO country code.');
            }
            $item['quantity'] = (int) $item['quantity'];
            $item['total_value_minor'] = bcmul((string) $item['quantity'], $item['unit_value_minor'], 0);
            $total = bcadd($total, $item['total_value_minor'], 0);
            if (bccomp($total, '999999999999') > 0) {
                self::fail('items', 'Declared total exceeds supported limits.');
            }
            if (isset($item['net_grams'],$item['gross_grams']) && $item['net_grams'] > $item['gross_grams']) {
                self::fail("items.$i.gross_grams", 'Gross weight must cover net weight.');
            }$gross += (int) ($item['gross_grams'] ?? 0);
            $qty = 0;
            $seen = [];
            $lineWeight = 0;
            $weighted = 0;
            foreach ($item['allocations'] as $j => &$a) {
                $a['package'] = (int) $a['package'];
                $a['quantity'] = (int) $a['quantity'];
                $n = $a['package'];
                if (! isset($draft['packages'][$n - 1]) || isset($seen[$n])) {
                    self::fail("items.$i.allocations.$j.package", 'Use a distinct package from the bound shipment revision.');
                }$seen[$n] = true;
                $qty += $a['quantity'];
                if (isset($a['weight_grams'])) {
                    $a['weight_grams'] = (int) $a['weight_grams'];
                    $weighted++;
                    $lineWeight += $a['weight_grams'];
                    $allocated[$n] = ($allocated[$n] ?? 0) + $a['weight_grams'];
                }
            }
            unset($a);
            if ($item['allocations'] && $qty !== $item['quantity']) {
                self::fail("items.$i.allocations", 'Allocated quantities must equal item quantity.');
            }
            if ($weighted && ($weighted !== count($item['allocations']) || ! isset($item['gross_grams']) || $lineWeight !== (int) $item['gross_grams'])) {
                self::fail("items.$i.allocations", 'Allocated weights must equal total line gross grams.');
            }
        }
        unset($item);
        foreach ($allocated as $n => $weight) {
            if ($weight > $draft['packages'][$n - 1]['weight_grams']) {
                self::fail('items', 'Allocated goods exceed package gross weight.');
            }
        }if ($gross > array_sum(array_column($draft['packages'], 'weight_grams'))) {
            self::fail('items', 'Goods gross weight exceeds shipment packages.');
        }
        if ($match && $total !== $draft['declared_value_minor']) {
            self::fail('declared_value_minor', 'Declared value must equal the exact item total.');
        }
        $d['declared_total_minor'] = $total;
        $d['currency'] = $draft['currency'];
        $d['exponent'] = $currency->exponent;

        return $d;
    }

    public static function input(array $normalized): array
    {
        unset($normalized['declared_total_minor'],$normalized['currency'],$normalized['exponent']);
        if (is_array($normalized['items'] ?? null)) {
            foreach ($normalized['items'] as &$item) {
                if (is_array($item)) {
                    unset($item['total_value_minor']);
                }
            }
        }

        return $normalized;
    }

    public static function legacy(array $c): array
    {
        return array_map(fn ($i) => ['description' => ($i['description_en'] ?? '') ?: ($i['description_ar'] ?? 'Goods'), 'quantity' => $i['quantity'], 'value_minor' => $i['total_value_minor'], 'country' => $i['origin_country'], 'hs_code' => $i['hs_code'] ?? null], $c['items']);
    }

    public static function local(array $c, array $draft): array
    {
        $missing = [];
        if (! empty($c['categories']) && empty($c['restricted_acknowledgement'])) {
            $missing[] = 'restricted_acknowledgement';
        }foreach (['purpose', 'invoice_date', 'incoterm', 'duty_payer'] as $field) {
            if (empty($c[$field])) {
                $missing[] = $field;
            }
        }
        foreach (['exporter', 'importer'] as $side) {
            foreach (['name', 'country', 'city', 'line1', 'phone'] as $field) {
                if (empty($c[$side][$field])) {
                    $missing[] = "$side.$field";
                }
            }
        }
        foreach ($c['items'] as $i => $item) {
            foreach (['description_en', 'description_ar', 'allocations'] as $field) {
                if (empty($item[$field])) {
                    $missing[] = "items.$i.$field";
                }
            }if (($c['purpose'] ?? '') !== 'documents' && $item['unit_value_minor'] === '0') {
                $missing[] = "items.$i.unit_value_minor";
            }
        }
        $packages = [];
        foreach ($c['items'] as $item) {
            foreach ($item['allocations'] as $a) {
                $packages[$a['package']] = true;
            }
        }foreach ($draft['packages'] as $i => $package) {
            if (! isset($packages[$i + 1])) {
                $missing[] = 'packages.'.$i.'.contents';
            }
        }

        return array_values(array_unique($missing));
    }
}
