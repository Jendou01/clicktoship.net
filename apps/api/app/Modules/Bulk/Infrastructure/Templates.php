<?php

namespace App\Modules\Bulk\Infrastructure;

use App\Modules\Bulk\Domain\Columns;

final class Templates
{
    public function download(string $locale, string $format, string $variant = 'standard'): mixed
    {
        $columns = Columns::labels();
        if ($variant === 'international') {
            unset($columns['items_json']);
        }
        $labels = array_map(fn ($v) => $v[$locale === 'ar' ? 1 : 0], $columns);
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($labels) {
                $f = fopen('php://output', 'wb');
                fwrite($f, "\xEF\xBB\xBF");
                fputcsv($f, $labels, ',', '"', '');
                fclose($f);
            }, 'bulk-'.($variant === 'international' ? 'international-' : '').$locale.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-template-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Shipments" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $cells = '';
        $n = 1;
        foreach ($labels as $label) {
            $i = $n++;
            $col = '';
            while ($i > 0) {
                $i--;
                $col = chr(65 + $i % 26).$col;
                $i = intdiv($i, 26);
            }$cells .= '<c r="'.$col.'1" t="inlineStr"><is><t>'.htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.$cells.'</row></sheetData></worksheet>');
        $zip->close();

        return response()->download($path, 'bulk-'.($variant === 'international' ? 'international-' : '').$locale.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
    }
}
