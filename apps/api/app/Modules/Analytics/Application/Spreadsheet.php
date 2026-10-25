<?php

namespace App\Modules\Analytics\Application;

use ZipArchive;

final class Spreadsheet
{
    public function cell(mixed $value, bool $number = false): string
    {
        $s = is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '');
        if ($number && preg_match('/^-?[0-9]+$/D', $s)) {
            return $s;
        }

        return preg_match('/^[\s\p{Z}\p{C}]*[=+\-@]/u', $s) ? "'".$s : $s;
    }

    public function write(array $columns, array $rows, string $format, string $locale): string
    {
        abort_if(count($rows) > 1000, 413);
        // Stable approved machine column names; Arabic Unicode values are preserved.
        $data = [$columns];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $key) {
                $line[] = $row[$key] ?? null;
            }$data[] = $line;
        }
        if ($format === 'csv') {
            $h = fopen('php://temp/maxmemory:2097152', 'w+');
            fwrite($h, "\xEF\xBB\xBF");
            foreach ($data as $i => $line) {
                fputcsv($h, array_map(fn ($v, $key) => $this->cell($v, $i > 0 && in_array($key, ['delta', 'posted_after', 'reserved_after', 'sequence', 'amount', 'cutoff', 'total_minor', 'selling_minor', 'discount_minor', 'exponent', 'count', 'row_count'], true)), $line, $columns), ',', '"', '');
            }
            rewind($h);
            $bytes = stream_get_contents($h);
            fclose($h);
        } else {
            abort_unless($format === 'xlsx', 422);
            $escape = fn ($s) => htmlspecialchars(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) ($s ?? '')), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="'.($locale === 'ar' ? '1' : '0').'"/></sheetViews><sheetData>';
            foreach ($data as $i => $line) {
                $sheet .= '<row r="'.($i + 1).'">';
                foreach ($line as $value) {
                    $sheet .= '<c t="inlineStr"><is><t xml:space="preserve">'.$escape(is_bool($value) ? ($value ? 'true' : 'false') : $value).'</t></is></c>';
                }$sheet .= '</row>';
            }$sheet .= '</sheetData></worksheet>';
            $path = tempnam(sys_get_temp_dir(), 'cts-report-');
            chmod($path, 0600);
            try {
                $z = new ZipArchive;
                abort_unless($z->open($path, ZipArchive::OVERWRITE) === true, 503);
                $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
                $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
                $z->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>');
                $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
                $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
                $z->close();
                $bytes = file_get_contents($path);
            } finally {
                unlink($path);
            }
        }
        abort_if(strlen($bytes) > 2097152, 413);

        return $bytes;
    }
}
