<?php

namespace App\Modules\Customs\Infrastructure;

final class DraftPdf
{
    public function render(object $doc, array $c, array $draft): string
    {
        $ar = $doc->locale === 'ar';
        $text = fn ($en, $arabic) => $ar ? $arabic : $en;
        $pdf = new class('P', 'mm', 'A4', true, 'UTF-8', false) extends \TCPDF
        {
            public function disableExternalAnnotations(): void
            {
                $this->tcpdflink = false;
            }

            public function Footer(): void
            {
                $this->SetY(-16);
                $this->SetFont('dejavusans', '', 8);
                $this->setRTL(false);
                $this->MultiCell(0, 4, 'DRAFT — NOT CUSTOMS CLEARANCE', 0, 'C');
                $this->setRTL(true);
                $this->MultiCell(0, 4, 'مسودة — ليست تخليصًا جمركيًا', 0, 'C');
            }
        };
        $pdf->disableExternalAnnotations();
        $pdf->setPrintHeader(false);
        $pdf->SetCreator('ClickToShip');
        $pdf->SetAuthor('ClickToShip — merchant draft');
        $pdf->SetTitle('DRAFT '.$doc->number);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 22);
        $pdf->setRTL($ar);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->AddPage();
        $line = function (string $value, int $size = 10) use ($pdf) {
            $pdf->SetFont('dejavusans', '', $size);
            $value = preg_replace('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);
            $pdf->MultiCell(0, 7, $value, 0, '', false, 1);
        };
        $line('ClickToShip · '.$text('DRAFT', 'مسودة'), 20);
        $line($doc->kind === 'invoice' ? $text('COMMERCIAL INVOICE — DRAFT', 'فاتورة تجارية — مسودة') : $text('PACKING LIST — DRAFT', 'قائمة تعبئة — مسودة'), 16);
        $line($text('Merchant declaration only. No legal approval, signature, customs clearance, duties or carrier acceptance.', 'إقرار تاجر فقط. لا موافقة قانونية أو توقيع أو تخليص جمركي أو رسوم أو قبول ناقل.'));
        $pdf->Ln(3);
        $line($doc->number.' · '.$doc->invoice_date);
        $line($text('Shipment', 'الشحنة').': '.$doc->shipment_id.' · '.$text('revision', 'النسخة').': '.$doc->revision.' / '.$doc->version);
        foreach (['exporter' => ['Exporter', 'المصدر'], 'importer' => ['Importer', 'المستورد']] as $side => $labels) {
            $line($text(...$labels), 12);
            $p = $c[$side];
            $line(implode(' · ', array_filter(array_map(fn ($key) => (string) ($p[$key] ?? ''), ['name', 'business_id', 'tax_id', 'country', 'city', 'state', 'postal', 'line1', 'phone', 'email']))));
        }
        $names = ['commercial' => 'تجاري', 'non_commercial' => 'غير تجاري', 'gift' => 'هدية', 'sample' => 'عينة', 'documents' => 'مستندات', 'sender' => 'المرسل', 'recipient' => 'المستلم', 'third_party' => 'طرف ثالث', 'unverified' => 'غير متحقق', 'piece' => 'قطعة', 'set' => 'مجموعة', 'box' => 'صندوق', 'kg' => 'كيلوغرام'];
        $valueLabel = fn ($v) => $ar ? ($names[$v] ?? $v) : $v;
        $line($text('Reason for export', 'سبب التصدير').': '.$valueLabel($c['purpose'] ?? ''));
        $line($text('Incoterms 2020 (merchant selection): ', 'شروط التجارة 2020 (اختيار التاجر): ').($c['incoterm'] ?? '').' · '.$text('Duty payer', 'دافع الرسوم').': '.$valueLabel($c['duty_payer'] ?? ''));
        if (! empty($c['merchant_reference'])) {
            $line($text('Merchant reference', 'مرجع التاجر').': '.$c['merchant_reference']);
        }if (! empty($c['customs_reference'])) {
            $line($text('Customs reference', 'مرجع الجمارك').': '.$c['customs_reference']);
        }
        $money = fn ($minor) => bcdiv((string) $minor, bcpow('10', (string) $c['exponent'], 0), $c['exponent']).' '.$c['currency'];
        foreach ($c['items'] as $i => $item) {
            $pdf->Ln(2);
            $pdf->setRTL(false);
            $line(($i + 1).'. '.$item['description_en'], 11);
            $pdf->setRTL(true);
            $line($item['description_ar'], 11);
            $pdf->setRTL($ar);
            $line($text('Quantity / unit', 'الكمية / الوحدة').': '.$item['quantity'].' '.$valueLabel($item['uom']).' · '.$text('Origin', 'المنشأ').': '.$item['origin_country'].$text(' · HS (merchant): ', ' · رمز HS المقدم من التاجر: ').($item['hs_code'] ?? ''));
            if ($doc->kind === 'invoice') {
                $line($text('Unit price', 'سعر الوحدة').': '.$money($item['unit_value_minor']).' · '.$text('Line total', 'إجمالي البند').': '.$money($item['total_value_minor']));
            } else {
                foreach ($item['allocations'] as $a) {
                    $line($text('Package', 'الطرد').' '.$a['package'].': '.$a['quantity'].' '.$valueLabel($item['uom']).(isset($a['weight_grams']) ? ' · '.$a['weight_grams'].' g' : ''));
                }
            }
            foreach (['sku', 'material', 'intended_use', 'category', 'notes'] as $key) {
                if (! empty($item[$key])) {
                    $fieldLabels = ['sku' => 'رمز المنتج', 'material' => 'المادة أو التركيب', 'intended_use' => 'الاستخدام المقصود', 'category' => 'فئة المنتج', 'notes' => 'ملاحظات'];
                    $line(($ar ? ($fieldLabels[$key] ?? $key) : $key).': '.$item[$key]);
                }
            }
        }
        if ($doc->kind === 'invoice') {
            $line($text('Total declared value', 'إجمالي القيمة المعلنة').': '.$money($c['declared_total_minor']), 14);
        } else {
            $line($text('Packages from bound shipment revision', 'الطرود من نسخة الشحنة المرتبطة'), 12);
            foreach ($draft['packages'] as $i => $p) {
                $line(($i + 1).': '.$p['weight_grams'].' g · '.$p['length_mm'].' × '.$p['width_mm'].' × '.$p['height_mm'].' mm');
            }$line($text('Package count', 'عدد الطرود').': '.count($draft['packages']).' · '.array_sum(array_column($draft['packages'], 'weight_grams')).' g');
        }
        foreach (['notes', 'instructions'] as $key) {
            if (! empty($c[$key])) {
                $line($c[$key]);
            }
        }$line($text('Prepared from structured merchant data; HS codes and trade terms are not externally verified.', 'أُعدت من بيانات التاجر المنظمة؛ رموز HS وشروط التجارة غير متحقق منها خارجيًا.'));

        return $pdf->Output('draft.pdf', 'S');
    }
}
