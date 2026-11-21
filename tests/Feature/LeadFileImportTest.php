<?php

use App\Models\LeadContact;
use App\Models\LeadEntry;
use App\Models\LeadImportBatch;
use App\Models\LeadSource;
use App\Services\Leads\DuplicateFileException;
use App\Services\Leads\LeadCaptureService;
use App\Services\Leads\LeadFileImporter;
use App\Services\Leads\LegacyUserLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** File import (spec 05 §6). */
function makeCsv(string $path, string $content): void
{
    file_put_contents($path, $content);
}

function makeXlsx(string $path, array $rows): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $shared = [];
    $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $ref = 'A';
    foreach ($rows as $rowIndex => $row) {
        $sheet .= '<row r="'.($rowIndex + 1).'">';
        foreach ($row as $colIndex => $cell) {
            $shared[] = (string) $cell;
            $sheet .= '<c r="'.chr(65 + $colIndex).($rowIndex + 1).'" t="s"><v>'.(count($shared) - 1).'</v></c>';
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $sharedXml = '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($shared as $i => $text) {
        $sharedXml .= '<si><t>'.htmlspecialchars($text).'</t></si>';
    }
    $sharedXml .= '</sst>';

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

beforeEach(function () {
    $this->importer = new LeadFileImporter(new LeadCaptureService(new LegacyUserLinker));
    $this->path = storage_path('framework/testing/import-test');
    @mkdir(dirname($this->path), 0777, true);
    @unlink($this->path.'.csv');
    @unlink($this->path.'.xlsx');
});

afterEach(function () {
    @unlink($this->path.'.csv');
    @unlink($this->path.'.xlsx');
});

it('auto-maps Persian header aliases and commits rows', function () {
    makeCsv($this->path.'.csv', <<<'CSV'
        موبایل,نام,تاریخ
        ۰۹۱۲۱۲۳۴۵۶۷,سارا,1405/08/30
        9122345678,Reza,2026-11-19
        9123456789,
        CSV);

    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);
    $batch = $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source);

    expect($batch->total)->toBe(3)
        ->and($batch->inserted)->toBe(3)
        ->and($batch->invalid)->toBe(0)
        ->and($batch->file_hash)->not->toBeNull();

    expect(LeadContact::query()->count())->toBe(3)
        ->and(LeadContact::query()->where('phone', '9121234567')->first()?->name)->toBe('سارا');

    $entry = LeadEntry::query()->whereHas('contact', fn ($q) => $q->where('phone', '9121234567'))->first();
    expect($entry?->captured_at?->format('Y-m-d'))->toBe('2026-11-21'); // Jalali 1405/08/30
});

it('counts returning leads instead of duplicates on re-import', function () {
    makeCsv($this->path.'.csv', "phone,name\n9121234567,Ali\n9129876543,Sara\n");

    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);

    $first = $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source);
    expect($first->inserted)->toBe(2)->and($first->returning_leads)->toBe(0);

    // Second import of the same rows: new entries, flagged returning.
    $second = $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source, [], [], null, true);
    expect($second->inserted)->toBe(0)
        ->and($second->returning_leads)->toBe(2)
        ->and($second->duplicates)->toBe(0);
});

it('skips exact in-file repeats and reports where they repeat', function () {
    makeCsv($this->path.'.csv', "phone,name\n9121234567,Ali\n9121234567,Ali\n9121234567,Ali Reza\n");

    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);
    $batch = $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source);

    // Rows 1+2 are exact repeats; row 3 has a different name → returning.
    expect($batch->duplicates)->toBe(1)
        ->and($batch->inserted)->toBe(1)
        ->and($batch->returning_leads)->toBe(1)
        ->and($batch->errors[0]['reason'])->toBe('in_file_repeat')
        ->and($batch->errors[0]['repeats_row'])->toBe(2);
});

it('counts invalid phones with reasons and never drops them', function () {
    makeCsv($this->path.'.csv', "phone\n2121234567\n");

    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);
    $batch = $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source);

    expect($batch->invalid)->toBe(1)
        ->and(LeadEntry::query()->where('invalid_phone', true)->count())->toBe(1)
        ->and(LeadContact::query()->count())->toBe(0);
});

it('warns when the same file hash was imported before', function () {
    makeCsv($this->path.'.csv', "phone\n9121234567\n");
    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);

    $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source);

    $this->importer->commit($this->path.'.csv', 'leads.csv', 'csv', $source, [], [], null, false);
})->throws(DuplicateFileException::class);

it('creates a custom source at commit time and stores extra columns as payload', function () {
    makeCsv($this->path.'.csv', "موبایل,نام,شهر,سطح\n9121234567,Ali,Tehran,3\n");

    $source = $this->importer->createCustomSource('لید نمایشگاه', 'expo_lead', '#FF0000', ['شهر', 'سطح']);

    expect($source->slug)->toStartWith('custom_')
        ->and($source->kind)->toBe('custom')
        ->and($source->importable)->toBeTrue()
        ->and($source->crm_lead_type)->toBe('expo_lead');

    $batch = $this->importer->commit($this->path.'.csv', 'expo.csv', 'csv', $source, [], ['شهر', 'سطح']);

    expect($batch->inserted)->toBe(1)
        ->and($source->entries()->first()?->payload)->toBe(['شهر' => 'Tehran', 'سطح' => '3']);
});

it('imports xlsx workbooks', function () {
    makeXlsx($this->path.'.xlsx', [
        ['شماره موبایل', 'نام و نام خانوادگی'],
        ['09121234567', 'Ali Ahmadi'],
        ['9129876543', 'Sara Karimi'],
    ]);

    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);
    $batch = $this->importer->commit($this->path.'.xlsx', 'leads.xlsx', 'xlsx', $source);

    expect($batch->inserted)->toBe(2)
        ->and(LeadContact::query()->where('phone', '9121234567')->first()?->name)->toBe('Ali Ahmadi');
});

it('keeps batch bookkeeping per source', function () {
    makeCsv($this->path.'.csv', "phone\n9121234567\n");
    $source = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);
    $other = LeadSource::factory()->create(['driver' => 'stored', 'importable' => true]);

    $this->importer->commit($this->path.'.csv', 'a.csv', 'csv', $source);
    // Same file into another source is NOT a duplicate-file warning.
    $batch = $this->importer->commit($this->path.'.csv', 'a.csv', 'csv', $other);

    expect($batch->inserted)->toBe(1)
        ->and(LeadImportBatch::query()->count())->toBe(2);
});
