<?php

namespace Tests\Performance;

use App\Modules\Analytics\Application\Spreadsheet;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BulkTestCase;
use Tests\Support\MeasuresQa;

final class BulkBaselineTest extends BulkTestCase
{
    use DatabaseMigrations, MeasuresQa;

    public static function formats(): array
    {
        return [['csv'], ['xlsx']];
    }

    #[DataProvider('formats')]
    public function test_thousand_row_import_baseline(string $format): void
    {
        Storage::fake('local');
        $f = $this->bulkFixture();
        $rows = array_map(fn () => array_replace($this->csvRow($f['f']['service']), ['description' => 'Synthetic '.bin2hex(random_bytes(32))]), range(1, 1000));
        $bytes = $format === 'csv' ? $this->csv($rows) : app(Spreadsheet::class)->write(array_keys($rows[0]), $rows, 'xlsx', 'en');
        if ($format === 'xlsx') {
            $path = tempnam(sys_get_temp_dir(), 'qa-bulk-');
            try {
                file_put_contents($path, $bytes);
                $z = new \ZipArchive;
                $z->open($path);
                $xml = new \DOMDocument;
                $xml->loadXML($z->getFromName('xl/worksheets/sheet1.xml'));
                foreach ($xml->getElementsByTagName('row') as $row) {
                    $column = 0;
                    foreach ($row->getElementsByTagName('c') as $cell) {
                        $n = ++$column;
                        $name = '';
                        while ($n > 0) {
                            $n--;
                            $name = chr(65 + $n % 26).$name;
                            $n = intdiv($n, 26);
                        }
                        $cell->setAttribute('r', $name.$row->getAttribute('r'));
                    }
                }
                $z->addFromString('xl/worksheets/sheet1.xml', $xml->saveXML());
                $z->close();
                $bytes = file_get_contents($path);
            } finally {
                unlink($path);
            }
        }
        $this->measure('bulk_1000_'.$format, function () use ($f, $bytes, $format) {
            $id = $this->uploadTo($f, $bytes, $format);
            $this->drain($f['m'], $id);

            return $this->bearer($f['token'], 'GET', '/api/v1/bulk/imports/'.$id)->assertOk()->assertJsonPath('data.batch.row_count', 1000);
        }, 3);
        $this->emitMeasures();
    }
}
