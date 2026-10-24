<?php

namespace App\Modules\Bulk\Infrastructure;

use App\Modules\Bulk\Domain\ImportFailure;
use Generator;
use XMLReader;
use ZipArchive;

/** Deliberately restricted, streaming data reader. No Excel evaluation, network, styles or macros. */
final class SpreadsheetReader
{
    private int $expandedBytes = 0;

    private bool $countExpansion = true;

    public function rows(string $path, string $format, string $delimiter = ','): Generator
    {
        if (! config('bulk.structural_only')) {
            throw new ImportFailure('MALWARE_SCANNER_REQUIRED');
        }
        $start = hrtime(true);
        $rows = $format === 'csv' ? $this->csv($path, $delimiter) : $this->xlsx($path);
        $count = 0;
        foreach ($rows as $number => $cells) {
            if (++$count > config('bulk.max_rows') + 1 || hrtime(true) - $start > config('bulk.processing_seconds') * 1e9) {
                throw new ImportFailure('ROW_OR_TIME_LIMIT');
            }
            if (count($cells) > config('bulk.max_columns')) {
                throw new ImportFailure('COLUMN_LIMIT');
            }
            foreach ($cells as &$cell) {
                if (! mb_check_encoding($cell, 'UTF-8') || str_contains($cell, "\0") || strlen($cell) > config('bulk.max_cell_bytes')) {
                    throw new ImportFailure('CELL_OR_ENCODING');
                }
                if (preg_match('/^[\s\x{FEFF}]*[=@]/u', $cell) || preg_match('/^[\s]*[+-](?!\p{Nd}+\s*$)/u', $cell)) {
                    throw new ImportFailure('FORMULA_CONTENT');
                }
                $cell = \Normalizer::normalize($cell, \Normalizer::FORM_C);
            }
            unset($cell);
            yield $number => $cells;
        }
        if ($count < 2) {
            throw new ImportFailure('EMPTY_FILE');
        }
    }

    private function csv(string $path, string $delimiter): Generator
    {
        $f = fopen($path, 'rb');
        if (fread($f, 3) !== "\xEF\xBB\xBF") {
            rewind($f);
        }
        $row = [];
        $cell = '';
        $state = 'start';
        $bytes = 0;
        $number = 1;
        $skipLf = false;
        try {
            while (! feof($f)) {
                $chunk = fread($f, 65536);
                if ($chunk === false) {
                    throw new ImportFailure('FILE_READ');
                }
                for ($i = 0, $n = strlen($chunk); $i < $n; $i++) {
                    $char = $chunk[$i];
                    if ($skipLf) {
                        $skipLf = false;
                        if ($char === "\n") {
                            continue;
                        }
                    }
                    if (++$bytes > config('bulk.max_record_bytes')) {
                        throw new ImportFailure('RECORD_LIMIT');
                    }
                    if ($state === 'quoted') {
                        if ($char === '"') {
                            $state = 'afterquote';
                        } else {
                            $cell .= $char;
                        }
                    } elseif ($state === 'afterquote' && $char === '"') {
                        $cell .= '"';
                        $state = 'quoted';
                    } elseif ($char === $delimiter) {
                        $row[] = $cell;
                        $cell = '';
                        $state = 'start';
                        if (count($row) >= config('bulk.max_columns')) {
                            throw new ImportFailure('COLUMN_LIMIT');
                        }
                    } elseif ($char === "\r" || $char === "\n") {
                        $row[] = $cell;
                        yield $number++ => $row;
                        $row = [];
                        $cell = '';
                        $state = 'start';
                        $bytes = 0;
                        $skipLf = $char === "\r";
                    } elseif ($state === 'start' && $char === '"') {
                        $state = 'quoted';
                    } elseif ($state === 'afterquote' || $char === '"') {
                        throw new ImportFailure('INVALID_CSV_QUOTING');
                    } else {
                        $cell .= $char;
                        $state = 'unquoted';
                    }
                    if (strlen($cell) > config('bulk.max_cell_bytes')) {
                        throw new ImportFailure('CELL_OR_ENCODING');
                    }
                }
            }
            if ($state === 'quoted') {
                throw new ImportFailure('INVALID_CSV_QUOTING');
            }
            if ($bytes > 0) {
                $row[] = $cell;
                yield $number => $row;
            }
        } finally {
            fclose($f);
        }
    }

    private function xml($stream): XMLReader
    {
        $temp = tmpfile();
        $path = stream_get_meta_data($temp)['uri'];
        $tail = '';
        $bytes = 0;
        while (! feof($stream)) {
            $part = fread($stream, 65536);
            $bytes += strlen($part ?: '');
            if ($this->countExpansion) {
                $this->expandedBytes += strlen($part ?: '');
            }
            if ($this->expandedBytes > config('bulk.max_expanded_bytes')) {
                fclose($temp);
                throw new ImportFailure('ZIP_EXPANSION_LIMIT');
            }
            if ($bytes > config('bulk.max_expanded_bytes')) {
                fclose($temp);
                throw new ImportFailure('ZIP_EXPANSION_LIMIT');
            }
            if ($part === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $tail.$part)) {
                fclose($temp);
                throw new ImportFailure('XML_ENTITY');
            }
            fwrite($temp, $part);
            $tail = substr($part, -32);
        }
        rewind($temp);
        // XMLReader's stream API is PHP 8.4+. Copy only one bounded ZIP entry to a private temporary file.
        $xml = new XMLReader;
        if (! $xml->open($path, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            fclose($temp);
            throw new ImportFailure('CORRUPT_XML');
        }
        $xml->setParserProperty(XMLReader::LOADDTD, false);
        $xml->setParserProperty(XMLReader::SUBST_ENTITIES, false);
        $this->streams[spl_object_id($xml)] = $temp;

        return $xml;
    }

    private array $streams = [];

    private function close(XMLReader $xml): void
    {
        $xml->close();
        fclose($this->streams[spl_object_id($xml)]);
        unset($this->streams[spl_object_id($xml)]);
    }

    private function xlsx(string $path): Generator
    {
        $this->expandedBytes = 0;
        $this->countExpansion = true;
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ImportFailure('INVALID_XLSX');
        }
        $dictionary = tmpfile();
        $offsets = [];
        $expanded = 0;
        $names = [];
        $sheetCount = 0;
        $prior = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if ($zip->numFiles > config('bulk.max_zip_entries')) {
                throw new ImportFailure('ZIP_ENTRY_LIMIT');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $s = $zip->statIndex($i);
                $name = $s['name'];
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                if ((($attributes >> 16) & 0170000) === 0120000) {
                    throw new ImportFailure('ZIP_SYMLINK');
                }
                if (isset($names[$name]) || ! preg_match('~^(\[Content_Types\]\.xml|_rels/\.rels|docProps/(app|core)\.xml|xl/(workbook\.xml|_rels/workbook\.xml\.rels|styles\.xml|theme/theme1\.xml|sharedStrings\.xml|worksheets/sheet1\.xml))$~D', $name) || ($s['encryption_method'] ?? 0) !== 0) {
                    throw new ImportFailure('UNSUPPORTED_XLSX_PART');
                }
                $names[$name] = true;
                $expanded += $s['size'];
                if ($expanded > config('bulk.max_expanded_bytes') || $s['size'] > max(1, $s['comp_size']) * config('bulk.max_zip_ratio')) {
                    throw new ImportFailure('ZIP_EXPANSION_LIMIT');
                }
                // Every part is XML inspected before any worksheet rows are yielded.
                $stream = $zip->getStream($name);
                $xml = $this->xml($stream);
                fclose($stream);
                try {
                    while ($xml->read()) {
                        if ($xml->nodeType === XMLReader::PI || $xml->nodeType === XMLReader::DOC_TYPE || $xml->nodeType === XMLReader::ENTITY_REF) {
                            throw new ImportFailure('XML_ENTITY');
                        }
                        if ($xml->nodeType !== XMLReader::ELEMENT) {
                            continue;
                        }
                        if ($name === 'xl/workbook.xml' && $xml->localName === 'sheet' && ++$sheetCount > 1) {
                            throw new ImportFailure('UNSUPPORTED_XLSX_PART');
                        }
                        if ($name === '[Content_Types].xml' && preg_match('/macroEnabled|vba|oleObject|activeX/i', $xml->getAttribute('ContentType') ?? '')) {
                            throw new ImportFailure('ACTIVE_XLSX_CONTENT');
                        }
                        if (in_array($xml->localName, ['f', 'externalReference', 'hyperlink', 'oleObject', 'control', 'sheetProtection'])) {
                            throw new ImportFailure('ACTIVE_XLSX_CONTENT');
                        }
                        if ($xml->localName === 'Relationship') {
                            $target = $xml->getAttribute('Target') ?? '';
                            if ($xml->getAttribute('TargetMode') === 'External' || ! in_array($target, ['xl/workbook.xml', 'docProps/app.xml', 'docProps/core.xml', 'worksheets/sheet1.xml', 'styles.xml', 'sharedStrings.xml', 'theme/theme1.xml', '/xl/workbook.xml', '/docProps/app.xml', '/docProps/core.xml', '/xl/worksheets/sheet1.xml', '/xl/styles.xml', '/xl/sharedStrings.xml', '/xl/theme/theme1.xml'])) {
                                throw new ImportFailure('EXTERNAL_RELATIONSHIP');
                            }
                        }
                        if ($name === 'xl/sharedStrings.xml' && $xml->localName === 'si') {
                            $text = $xml->readString();
                            if (strlen($text) > config('bulk.max_cell_bytes') || count($offsets) >= 100000) {
                                throw new ImportFailure('SHARED_STRING_LIMIT');
                            }
                            $offsets[] = ftell($dictionary);
                            fwrite($dictionary, pack('N', strlen($text)).$text);
                        }
                    }
                    if (libxml_get_errors()) {
                        throw new ImportFailure('CORRUPT_XML');
                    }
                } finally {
                    $this->close($xml);
                }
            }
            if ($sheetCount !== 1) {
                throw new ImportFailure('INVALID_XLSX');
            }
            foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'] as $required) {
                if (! isset($names[$required])) {
                    throw new ImportFailure('INVALID_XLSX');
                }
            }
            $this->countExpansion = false;
            $stream = $zip->getStream('xl/worksheets/sheet1.xml');
            $xml = $this->xml($stream);
            fclose($stream);
            $row = [];
            $number = 0;
            $last = 0;
            $column = null;
            $type = '';
            $seen = [];
            try {
                while ($xml->read()) {
                    if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'row') {
                        $number = (int) $xml->getAttribute('r');
                        if ($number <= $last || $number > config('bulk.max_rows') + 1) {
                            throw new ImportFailure('ROW_ORDER_OR_LIMIT');
                        }
                        $last = $number;
                        $row = [];
                        $seen = [];
                        if ($xml->isEmptyElement) {
                            yield $number => [];
                        }
                    } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c') {
                        $ref = $xml->getAttribute('r');
                        if (! preg_match('/^([A-Z]{1,3})([0-9]+)$/D', $ref ?? '', $m) || (int) $m[2] !== $number) {
                            throw new ImportFailure('CELL_REFERENCE');
                        }
                        $column = 0;
                        foreach (str_split($m[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                        }
                        if ($column > config('bulk.max_columns') || isset($seen[$column])) {
                            throw new ImportFailure('COLUMN_LIMIT');
                        }
                        $seen[$column] = true;
                        $type = $xml->getAttribute('t') ?? 'n';
                        if (! in_array($type, ['n', 's', 'inlineStr', 'str'])) {
                            throw new ImportFailure('UNSUPPORTED_CELL_TYPE');
                        }
                        $row[$column - 1] = '';
                    } elseif ($xml->nodeType === XMLReader::ELEMENT && in_array($xml->localName, ['v', 'is']) && $column !== null) {
                        $text = $xml->readString();
                        if ($type === 's') {
                            if (! ctype_digit($text) || ! isset($offsets[(int) $text])) {
                                throw new ImportFailure('SHARED_STRING_REFERENCE');
                            }
                            fseek($dictionary, $offsets[(int) $text]);
                            $length = unpack('N', fread($dictionary, 4))[1];
                            $text = $length ? fread($dictionary, $length) : '';
                        }
                        $row[$column - 1] = $text;
                    } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'row') {
                        $cells = $row ? array_fill(0, max(array_keys($row)) + 1, '') : [];
                        foreach ($row as $i => $v) {
                            $cells[$i] = $v;
                        }
                        yield $number => $cells;
                        $column = null;
                    }
                }
                if (libxml_get_errors()) {
                    throw new ImportFailure('CORRUPT_XML');
                }
            } finally {
                $this->close($xml);
            }
        } finally {
            $zip->close();
            fclose($dictionary);
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
    }
}
