<?php

namespace App\Services\Leads;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal multi-format spreadsheet reader for the lead importer
 * (spec 05 §6): csv/txt (delimiter detection), xlsx and ods (both are
 * zip + XML). Returns one array of row arrays per sheet.
 */
final class FileSheets
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 20000;

    /** @return list<list<list<string|null>>>  [sheetIndex][rowIndex][colIndex] */
    public static function read(string $path, string $format): array
    {
        $format = strtolower($format);

        return match ($format) {
            'csv', 'txt' => [self::readCsv($path)],
            'xlsx' => [self::readXlsx($path)],
            'ods' => [self::readOds($path)],
            default => throw new RuntimeException("Unsupported file format: {$format}"),
        };
    }

    public static function detectFormat(string $clientName, string $path): string
    {
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));

        return in_array($ext, ['csv', 'txt', 'xlsx', 'xls', 'ods'], true) ? $ext : 'csv';
    }

    /** @return list<list<string|null>> */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot open file');
        }

        // Detect the delimiter from the first line.
        $first = (string) fgets($handle);
        rewind($handle);
        $delimiter = ',';
        $best = 0;
        foreach ([',', ';', "\t", '|'] as $candidate) {
            $count = substr_count($first, $candidate);
            if ($count > $best) {
                $best = $count;
                $delimiter = $candidate;
            }
        }

        $rows = [];
        $line = 0;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($line++ >= self::MAX_ROWS) {
                throw new RuntimeException('File exceeds the 20,000-row limit');
            }

            $rows[] = array_map(
                static fn ($cell) => trim((string) $cell) === '' ? null : trim((string) $cell),
                $row,
            );
        }
        fclose($handle);

        return $rows;
    }

    /** @return list<list<string|null>> */
    private static function readXlsx(string $path): array
    {
        [$sharedStrings, $sheets] = self::openWorkbook($path);

        $sheetPath = $sheets[0] ?? null;
        if ($sheetPath === null) {
            return [];
        }

        $xml = simplexml_load_string((string) file_get_contents($sheetPath));
        if ($xml === false) {
            throw new RuntimeException('Broken xlsx sheet');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $cells = [];
            $running = 0;
            foreach ($rowNode->c as $c) {
                $ref = (string) ($c['r'] ?? '');
                $index = $ref !== '' ? self::columnIndex($ref) : $running;
                $running = $index + 1;
                $type = (string) ($c['t'] ?? 'n');
                $value = null;

                if ($type === 's') {
                    $shared = (int) (string) $c->v;
                    $value = $sharedStrings[$shared] ?? null;
                } elseif ($type === 'inlineStr') {
                    $value = (string) $c->is->t;
                } elseif (isset($c->v)) {
                    $value = (string) $c->v;
                }

                $cells[$index] = $value === '' ? null : $value;
            }

            if ($cells !== []) {
                $max = max(array_keys($cells));
                $rows[] = array_map(static fn ($i) => $cells[$i] ?? null, range(0, $max));
            }
        }

        return $rows;
    }

    /** @return array{0: list<string>, 1: list<string>} sharedStrings, sheet XML paths */
    private static function openWorkbook(string $path): array
    {
        if (! is_readable($path) || filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('File too large or unreadable (limit 10 MB)');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open workbook archive');
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml !== false) {
                // Plain <si><t>…</t></si> and rich-text <si><r><t>…</t></r>…</si>.
                foreach ($xml->si as $si) {
                    $parts = [];
                    if (isset($si->r)) {
                        foreach ($si->r as $run) {
                            $parts[] = (string) $run->t;
                        }
                    } else {
                        $parts[] = (string) $si->t;
                    }
                    $sharedStrings[] = implode('', $parts);
                }
            }
        }

        // First sheet, resolved through the workbook rels (order-safe).
        $workbook = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $sheets = [];

        if ($workbook !== false && $rels !== false) {
            $relMap = [];
            foreach ($rels->Relationship as $rel) {
                $relMap[(string) $rel['Id']] = (string) $rel['Target'];
            }

            foreach ($workbook->sheets->sheet as $sheet) {
                $rid = (string) $sheet->attributes('r', true)['id'];
                $target = $relMap[$rid] ?? null;
                if ($target !== null) {
                    $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.ltrim($target, '/');
                    $stream = $zip->getFromName($target);
                    if ($stream !== false) {
                        $tmp = tempnam(sys_get_temp_dir(), 'sheet');
                        file_put_contents((string) $tmp, $stream);
                        $sheets[] = (string) $tmp;
                    }
                }
            }
        }

        $zip->close();

        return [$sharedStrings, $sheets];
    }

    /** @return list<list<string|null>> */
    private static function readOds(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Cannot open ODS archive');
        }

        $content = $zip->getFromName('content.xml');
        $zip->close();
        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new RuntimeException('Broken ODS content');
        }

        $rows = [];
        $first = $xml->xpath('//table:table')[0] ?? null;
        if ($first instanceof SimpleXMLElement) {
            foreach ($first->xpath('.//table:table-row') ?: [] as $rowNode) {
                $cells = [];
                foreach ($rowNode->xpath('./table:table-cell') ?: [] as $cell) {
                    $repeat = (int) ($cell['number-columns-repeated'] ?? 1);
                    $textNodes = $cell->xpath('./text:p') ?: [];
                    $value = null;
                    foreach ($textNodes as $p) {
                        $value = ($value === null ? '' : $value.' ').trim((string) $p);
                    }
                    $value = ($value === null || trim($value) === '') ? null : trim($value);
                    for ($i = 0; $i < min($repeat, 50); $i++) {
                        $cells[] = $value;
                    }
                }

                while ($cells !== [] && end($cells) === null) {
                    array_pop($cells);
                }

                if ($cells !== []) {
                    $rows[] = $cells;
                }
            }
        }

        return $rows;
    }

    private static function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($reference), $m);
        $letters = $m[1] ?? 'A';
        $index = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
