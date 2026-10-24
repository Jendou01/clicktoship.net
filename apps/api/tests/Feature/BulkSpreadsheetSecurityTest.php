<?php

namespace Tests\Feature;

use App\Modules\Bulk\Domain\Columns;
use App\Modules\Bulk\Domain\ImportFailure;
use App\Modules\Bulk\Infrastructure\SpreadsheetReader;
use App\Modules\Bulk\Infrastructure\Templates;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BulkTestCase;

final class BulkSpreadsheetSecurityTest extends BulkTestCase
{
    use DatabaseMigrations;

    private function read(string $bytes, string $format = 'csv'): array
    {
        $file = tempnam(sys_get_temp_dir(), 'bulk-security-');
        file_put_contents($file, $bytes);
        try {
            return iterator_to_array(app(SpreadsheetReader::class)->rows($file, $format));
        } finally {
            unlink($file);
        }
    }

    private function book(array $parts = []): string
    {
        $file = tempnam(sys_get_temp_dir(), 'bulk-zip-');
        $z = new \ZipArchive;
        $z->open($file, \ZipArchive::OVERWRITE);
        $base = ['[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<workbook><sheets><sheet name="Data" sheetId="1"/></sheets></workbook>', 'xl/worksheets/sheet1.xml' => '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Currency</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>SAR</t></is></c></row></sheetData></worksheet>'];
        foreach (array_replace($base, $parts) as $n => $v) {
            $z->addFromString($n, $v);
        }$z->close();
        $data = file_get_contents($file);
        unlink($file);

        return $data;
    }

    public function test_xlsx_inline_and_shared_strings_are_data_only(): void
    {
        $rows = $this->read($this->book(), 'xlsx');
        $this->assertSame(['Currency'], $rows[1]);
        $this->assertSame(['SAR'], $rows[2]);
        $sheet = '<worksheet><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row><row r="2"><c r="A2" t="s"><v>1</v></c></row></sheetData></worksheet>';
        $rows = $this->read($this->book(['xl/sharedStrings.xml' => '<sst><si><t>العملة</t></si><si><t>SAR</t></si></sst>', 'xl/worksheets/sheet1.xml' => $sheet]), 'xlsx');
        $this->assertSame(['العملة'], $rows[1]);
        $this->assertSame(['SAR'], $rows[2]);
    }

    public static function threats(): array
    {
        return [
            'traversal' => ['../escape.xml', '<x/>'], 'macro' => ['xl/vbaProject.bin', 'binary'], 'second sheet' => ['xl/worksheets/sheet2.xml', '<worksheet/>'],
            'missing workbook sheet' => ['xl/workbook.xml', '<workbook/>'],
            'formula' => ['xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1"><f>WEBSERVICE("https://example.test")</f><v>1</v></c></row></sheetData></worksheet>'],
            'entity' => ['xl/styles.xml', '<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///etc/passwd">]><x>&secret;</x>'],
            'external' => ['xl/_rels/workbook.xml.rels', '<Relationships><Relationship Target="https://example.test" TargetMode="External"/></Relationships>'],
            'corrupt' => ['xl/styles.xml', '<broken>'], 'oversized column' => ['xl/worksheets/sheet1.xml', '<worksheet><row r="1"><c r="XFD1"><v>0</v></c></row></worksheet>'],
        ];
    }

    #[DataProvider('threats')]
    public function test_malicious_xlsx_is_rejected(string $name, string $data): void
    {
        $this->expectException(ImportFailure::class);
        $this->read($this->book([$name => $data]), 'xlsx');
    }

    public function test_zip_bomb_row_column_cell_encoding_and_formula_limits(): void
    {
        $cases = [[$this->book(['xl/styles.xml' => '<x>'.str_repeat('a', 200000).'</x>']), 'xlsx'], ["A\n".str_repeat('x', 5000)."\n", 'csv'], ["A\n\xFF\n", 'csv'], ["A\n=WEBSERVICE(1)\n", 'csv']];
        foreach ($cases as [$bytes,$format]) {
            try {
                $this->read($bytes, $format);
                $this->fail('Untrusted spreadsheet accepted');
            } catch (ImportFailure $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        config(['bulk.max_rows' => 2]);
        $this->expectException(ImportFailure::class);
        $this->read("A\n1\n2\n3\n");
    }

    public function test_streaming_csv_quotes_multiline_and_record_limits(): void
    {
        $rows = $this->read("A,B\r\n\"one,two\",\"say \"\"hello\"\"\nnext line\"\r\n");
        $this->assertSame(['one,two', "say \"hello\"\nnext line"], $rows[2]);
        config(['bulk.max_record_bytes' => 12]);
        $this->expectException(ImportFailure::class);
        $this->read("A,B\n\"1234567890123\",x\n");
    }

    public function test_ragged_row_fails_quarantine_without_exposing_partial_source(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, "A,B\n1,2,3\n");
        $this->drain($f['m'], $b);
        $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'failed', 'failure_code' => 'ROW_COLUMN_MISMATCH']);
        $this->bearer($f['token'], 'GET', "/api/v1/bulk/imports/$b/rows")->assertConflict();
    }

    public function test_bom_quoted_arabic_headers_and_formula_safe_csv_export(): void
    {
        $rows = $this->read("\xEF\xBB\xBF\"معرف الخدمة\",Currency\nvalue,SAR\n");
        $this->assertSame('معرف الخدمة', $rows[1][0]);
        foreach (['=1+1', '+966501234567', '-10', '@SUM(1)', '  =cmd'] as $value) {
            $this->assertSame("'".$value, Columns::safeExport($value));
        }
        $f = $this->bulkFixture();
        $row = array_replace($this->csvRow($f['f']['service']), ['weight_grams' => 'invalid']);
        $b = $this->uploadTo($f, $this->csv([$row]));
        $this->drain($f['m'], $b);
        $this->mapRows($f, $b);
        $response = $this->bearer($f['token'], 'GET', "/api/v1/bulk/imports/$b/errors?locale=ar")->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString("'+966501234567", $content);
        $this->assertStringContainsString('رقم الصف', $content);
        $response->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_tampered_encrypted_file_fails_closed_and_production_requires_scanner(): void
    {
        $f = $this->bulkFixture();
        $b = $this->uploadTo($f, $this->csv([$this->csvRow($f['f']['service'])]));
        $key = DB::table('bulk_files')->where('batch_id', $b)->value('storage_key');
        $path = storage_path('app/private/bulk/'.$key);
        $bytes = file_get_contents($path);
        $this->assertStringNotContainsString('Synthetic sender', $bytes);
        $bytes[30] = chr(ord($bytes[30]) ^ 1);
        file_put_contents($path, $bytes);
        $this->drain($f['m'], $b);
        $this->assertDatabaseHas('bulk_batches', ['id' => $b, 'state' => 'failed', 'failure_code' => 'FILE_TAMPERED']);
        $this->bearer($f['token'], 'GET', "/api/v1/bulk/imports/$b/rows")->assertConflict();
        config(['bulk.structural_only' => false]);
        $this->expectException(ImportFailure::class);
        $this->read("A\nvalue\n");
    }

    public function test_arabic_xlsx_template_with_data_runs_through_the_real_import_pipeline(): void
    {
        $f = $this->bulkFixture();
        $response = app(Templates::class)->download('ar', 'xlsx');
        $path = $response->getFile()->getPathname();
        try {
            $zip = new \ZipArchive;
            $zip->open($path);
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $row = $this->csvRow($f['f']['service']);
            $cells = '';
            $n = 1;
            foreach (Columns::labels() as $field => $label) {
                $i = $n++;
                $column = '';
                while ($i > 0) {
                    $i--;
                    $column = chr(65 + $i % 26).$column;
                    $i = intdiv($i, 26);
                }$cells .= '<c r="'.$column.'2" t="inlineStr"><is><t>'.htmlspecialchars($row[$field] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</sheetData>', '<row r="2">'.$cells.'</row></sheetData>', $sheet));
            $zip->close();
            $b = $this->uploadTo($f, file_get_contents($path), 'xlsx');
            $this->drain($f['m'], $b);
            $this->mapRows($f, $b);
            $this->assertSame(1, $this->bulkView($f, $b)['counts']['valid']);
            $this->act($f, $b, 'prepare', ['include_unpriced' => true])->assertOk();
            $this->drain($f['m'], $b);
            $this->assertDatabaseCount('shipments', 1);
            $this->assertDatabaseHas('shipments', ['state' => 'draft', 'financial_state' => 'none']);
            $this->assertDatabaseCount('balance_holds', 0);
        } finally {
            @unlink($path);
        }
    }

    public function test_common_workbook_theme_and_absolute_internal_targets_are_supported(): void
    {
        $data = $this->book(['xl/theme/theme1.xml' => '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Synthetic theme"/>', 'xl/_rels/workbook.xml.rels' => '<Relationships><Relationship Id="rId1" Target="/xl/worksheets/sheet1.xml"/></Relationships>']);
        $this->assertSame(['SAR'], $this->read($data, 'xlsx')[2]);
    }
}
