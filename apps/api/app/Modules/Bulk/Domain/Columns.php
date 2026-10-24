<?php

namespace App\Modules\Bulk\Domain;

use App\Modules\Shipping\Domain\ShipmentInput;

final class Columns
{
    public static function labels(): array
    {
        $labels = ['service_id' => ['Service ID', 'معرف الخدمة'], 'currency' => ['Currency', 'العملة'], 'description' => ['Contents description', 'وصف المحتويات'], 'declared_value_minor' => ['Declared value minor units', 'القيمة المعلنة بالهللة'], 'cod_minor' => ['COD minor units', 'الدفع عند الاستلام بالهللة'], 'goods_origin_country' => ['Country of manufacture', 'دولة التصنيع'], 'packages_json' => ['Packages JSON', 'الطرود JSON'], 'items_json' => ['Customs items JSON', 'البنود الجمركية JSON'], 'external_reference' => ['Merchant reference', 'مرجع التاجر']];
        foreach (['origin' => ['Sender', 'المرسل'], 'destination' => ['Recipient', 'المستلم'], 'return_address' => ['Return', 'الإرجاع']] as $side => [$en, $ar]) {
            foreach (['name' => ['name', 'الاسم'], 'phone' => ['phone', 'الهاتف'], 'email' => ['email', 'البريد الإلكتروني'], 'country' => ['country', 'الدولة'], 'city' => ['city', 'المدينة'], 'line1' => ['address', 'العنوان'], 'postal' => ['postal code', 'الرمز البريدي'], 'state' => ['state', 'المنطقة']] as $key => [$e,$a]) {
                $labels[$side.'_'.$key] = [$en.' '.$e, $ar.' '.$a];
            }
        }
        foreach (['weight_grams' => ['Weight grams', 'الوزن بالغرام'], 'length_mm' => ['Length mm', 'الطول بالملليمتر'], 'width_mm' => ['Width mm', 'العرض بالملليمتر'], 'height_mm' => ['Height mm', 'الارتفاع بالملليمتر']] as $key => $label) {
            $labels[$key] = $label;
        }

        return $labels;
    }

    public static function key(string $text): string
    {
        return mb_strtolower(preg_replace('/[\s_-]+/u', '', trim($text)));
    }

    public static function suggest(array $headers): array
    {
        $aliases = [];
        foreach (self::labels() as $field => $labels) {
            foreach ([$field, ...$labels] as $label) {
                $aliases[self::key($label)] = $field;
            }
        }
        $mapping = [];
        foreach ($headers as $i => $header) {
            if (isset($aliases[self::key($header)])) {
                $mapping[(string) $i] = $aliases[self::key($header)];
            }
        }

        return $mapping;
    }

    public static function draft(array $row, array $mapping): array
    {
        $v = [];
        foreach ($mapping as $i => $field) {
            $v[$field] = trim($row[(int) $i] ?? '');
        }
        $numeric = fn ($s) => strtr($s, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $party = function ($side) use ($v, $numeric) {
            $r = [];
            foreach (['country', 'city', 'name', 'phone', 'email', 'line1', 'postal', 'state'] as $key) {
                $text = $v[$side.'_'.$key] ?? '';
                if ($text !== '') {
                    $r[$key] = $key === 'country' ? strtoupper($text) : (in_array($key, ['phone', 'postal']) ? $numeric($text) : $text);
                }
            }

            return $r;
        };
        $json = function ($s) {
            try {
                $v = json_decode($s, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ImportFailure('INVALID_JSON');
            } if (! is_array($v) || ! array_is_list($v)) {
                throw new ImportFailure('INVALID_JSON');
            }

            return $v;
        };
        $draft = ['service_id' => $v['service_id'] ?? null, 'currency' => strtoupper($v['currency'] ?? ''), 'description' => $v['description'] ?? '', 'origin' => $party('origin'), 'destination' => $party('destination'), 'packages' => ! empty($v['packages_json']) ? $json($v['packages_json']) : [array_combine(['weight_grams', 'length_mm', 'width_mm', 'height_mm'], array_map(fn ($k) => $numeric($v[$k] ?? ''), ['weight_grams', 'length_mm', 'width_mm', 'height_mm']))], 'declared_value_minor' => $numeric(($v['declared_value_minor'] ?? '') ?: '0'), 'cod_minor' => $numeric(($v['cod_minor'] ?? '') ?: '0'), 'goods_origin_country' => empty($v['goods_origin_country']) ? null : strtoupper($v['goods_origin_country']), 'items' => empty($v['items_json']) ? [] : $json($v['items_json'])];
        if ($return = $party('return_address')) {
            $draft['return_address'] = $return;
        }

        return ShipmentInput::draft($draft);
    }

    public static function errorLabel(string $key, string $locale): string
    {
        $p = explode('.', $key);
        $flat = str_replace('.', '_', $key);
        if (isset(self::labels()[$flat])) {
            return self::labels()[$flat][$locale === 'ar' ? 1 : 0];
        }
        if ($p[0] === 'packages' && count($p) === 3 && isset(self::labels()[$p[2]])) {
            return self::labels()[$p[2]][$locale === 'ar' ? 1 : 0].' #'.((int) $p[1] + 1);
        }

        return ($locale === 'ar' ? 'راجع حقل الشحنة: ' : 'Review shipment field: ').$key;
    }

    public static function safeExport(string $text): string
    {
        return preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $text) ? "'".$text : $text;
    }
}
