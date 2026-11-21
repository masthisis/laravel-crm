<?php

namespace App\Services\Leads;

use App\Models\LeadImportBatch;
use App\Models\LeadSource;
use App\Support\Jalali;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Lead file import (spec 05 §6): csv/txt/xlsx/ods, automatic header-alias
 * column mapping (Persian aliases included), up to 12 extra payload
 * columns, returning-not-duplicate semantics, exact in-file repeats
 * skipped, invalid reasons kept, SHA-1 repeat-file warning, optional
 * custom source creation at commit time.
 */
class LeadFileImporter
{
    /** Header aliases: field → accepted header texts. */
    private const ALIASES = [
        'phone' => ['phone', 'mobile', 'tel', 'cell', 'شماره', 'موبایل', 'تلفن', 'شماره تماس', 'شماره موبایل', 'تلفن همراه'],
        'name' => ['name', 'first name', 'full name', 'نام', 'نام و نام خانوادگی', 'اسم'],
        'family' => ['family', 'last name', 'surname', 'نام خانوادگی', 'فامیل'],
        'captured_at' => ['date', 'created_at', 'تاریخ', 'تاریخ ثبت'],
    ];

    public const MAX_EXTRA_COLUMNS = 12;

    public function __construct(
        private readonly LeadCaptureService $capture,
    ) {}

    /**
     * Parse + auto-map. Returns a preview for the admin to adjust.
     *
     * @return array{format: string, headers: list<string>, rows: list<array<string, mixed>>, mapping: array<string, int|null>, extra: list<string>}
     */
    public function preview(string $path, string $clientName, string $format): array
    {
        $sheets = FileSheets::read($path, $format);
        $rows = $sheets[0] ?? [];

        $headers = [];
        foreach ($rows[0] ?? [] as $cell) {
            $headers[] = (string) $cell;
        }

        $mapping = $this->autoMap($headers);
        $extra = [];
        foreach ($headers as $i => $header) {
            if (! in_array($i, array_filter($mapping, static fn ($v) => $v !== null), true) && $header !== '' && count($extra) < self::MAX_EXTRA_COLUMNS) {
                $extra[] = $header;
            }
        }

        $bodyRows = [];
        foreach (array_slice($rows, 1, 10) as $row) {
            $bodyRows[] = $this->cellsToRecord($row, $headers);
        }

        return [
            'format' => $format,
            'headers' => $headers,
            'rows' => $bodyRows,
            'mapping' => $mapping,
            'extra' => $extra,
        ];
    }

    /**
     * Commit an import.
     *
     * @param  array<string, int|null>  $mapping  field → column index
     * @param  list<string>  $extra  extra payload column headers
     */
    public function commit(
        string $path,
        string $clientName,
        string $format,
        string|int|LeadSource $source,
        array $mapping = [],
        array $extra = [],
        ?int $adminId = null,
        bool $confirmDuplicateFile = false,
        ?callable $sourceFactory = null,
    ): LeadImportBatch {
        $source = $source instanceof LeadSource ? $source : LeadSource::query()->where('slug', $source)->firstOrFail();

        if ($source->driver !== LeadSource::DRIVER_STORED) {
            throw new RuntimeException('Only stored sources accept imports');
        }

        $fileHash = sha1_file($path) ?: null;

        $previous = LeadImportBatch::query()
            ->where('source_id', $source->id)
            ->where('file_hash', $fileHash)
            ->exists();

        if ($previous && ! $confirmDuplicateFile) {
            throw new DuplicateFileException($fileHash ?? '');
        }

        $batch = LeadImportBatch::create([
            'source_id' => $source->id,
            'file_name' => $clientName,
            'format' => $format,
            'file_hash' => $fileHash,
            'admin_id' => $adminId,
        ]);

        $sheets = FileSheets::read($path, $format);
        $rows = $sheets[0] ?? [];

        if (count($rows) > FileSheets::MAX_ROWS + 1) {
            throw new RuntimeException('File exceeds the 20,000-row limit');
        }

        $headers = array_map(static fn ($c) => (string) $c, $rows[0] ?? []);
        $mapping = $mapping === [] ? $this->autoMap($headers) : $mapping;

        $errors = [];
        $counts = ['total' => 0, 'inserted' => 0, 'returning' => 0, 'duplicates' => 0, 'invalid' => 0];
        $seen = []; // exact in-file repeats: phone + fingerprint
        $rowNumber = 1;

        foreach (array_slice($rows, 1) as $cells) {
            $rowNumber++;
            $record = $this->cellsToRecord($cells, $headers, $mapping, $extra, $batch->id, $rowNumber);
            $canonical = PhoneNormalizer::canonical($record['phone'] ?? null);
            $counts['total']++;

            $fingerprint = md5(serialize([
                'phone' => $canonical,
                'name' => $record['name'] ?? null,
                'family' => $record['family'] ?? null,
                'payload' => $record['payload'] ?? [],
            ]));
            $key = ($canonical ?? ($record['phone'] ?? '')).'|'.$fingerprint;

            if (isset($seen[$key])) {
                // Only an exact repeat of an earlier row of the same file is skipped.
                $counts['duplicates']++;
                $errors[] = ['row' => $rowNumber, 'phone' => $canonical, 'reason' => 'in_file_repeat', 'repeats_row' => $seen[$key]];

                continue;
            }
            $seen[$key] = $rowNumber;

            try {
                // Invalid phones are stored as flagged entries by the capture service.
                $result = $this->capture->capture($source, $record);
            } catch (Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'phone' => $canonical, 'reason' => $e->getMessage()];

                continue;
            }

            if ($result->status === CaptureResult::INVALID_PHONE) {
                $errors[] = ['row' => $rowNumber, 'phone' => $record['phone'], 'reason' => 'invalid_phone'];
            }

            match ($result->status) {
                CaptureResult::CAPTURED => $counts['inserted']++,
                CaptureResult::RETURNING => $counts['returning']++,
                CaptureResult::INVALID_PHONE => $counts['invalid']++,
                default => $counts['duplicates']++,
            };
        }

        $batch->fill([
            'total' => $counts['total'],
            'inserted' => $counts['inserted'],
            'returning_leads' => $counts['returning'],
            'duplicates' => $counts['duplicates'],
            'invalid' => $counts['invalid'],
            'errors' => $errors === [] ? null : array_slice($errors, 0, 200),
        ])->save();

        return $batch;
    }

    /** Create a custom source during commit (05 §2 / §6). */
    public function createCustomSource(string $name, ?string $leadTypeSlug, ?string $color, array $columns = []): LeadSource
    {
        return LeadSource::create([
            'name' => $name,
            'slug' => 'custom_'.Str::lower(Str::random(12)),
            'type' => 'manual',
            'color' => $color ?? '#6366f1',
            'kind' => LeadSource::KIND_CUSTOM,
            'driver' => LeadSource::DRIVER_STORED,
            'crm_lead_type' => $leadTypeSlug ?? 'lead_from_admin',
            'config' => ['columns' => $columns],
            'importable' => true,
            'sort' => 500,
        ]);
    }

    /** @param list<string> $headers */
    private function autoMap(array $headers): array
    {
        $mapping = ['phone' => null, 'name' => null, 'family' => null, 'captured_at' => null];

        foreach ($headers as $index => $header) {
            $normalized = trim(mb_strtolower(trim((string) $header)));
            foreach (self::ALIASES as $field => $aliases) {
                if ($mapping[$field] === null && in_array($normalized, $aliases, true)) {
                    $mapping[$field] = $index;
                }
            }
        }

        return $mapping;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<string, int|null>  $mapping
     * @return array<string, mixed>
     */
    private function cellsToRecord(array $cells, array $headers, array $mapping = [], array $extra = [], ?int $batchId = null, ?int $rowNumber = null): array
    {
        $record = [];
        foreach ($mapping as $field => $index) {
            if ($index !== null) {
                $record[$field] = $cells[$index] ?? null;
            }
        }

        $payload = [];
        if ($extra !== []) {
            foreach ($headers as $i => $header) {
                if (in_array($header, $extra, true)) {
                    $payload[$header] = $cells[$i] ?? null;
                }
            }
        }

        return [
            'phone' => isset($record['phone']) ? (string) $record['phone'] : null,
            'name' => $record['name'] ?? null,
            'family' => $record['family'] ?? null,
            'captured_at' => isset($record['captured_at']) ? $this->parseDate($record['captured_at']) : null,
            'payload' => $payload,
            'origin_ref' => $batchId !== null && $rowNumber !== null ? 'import:'.$batchId.':'.$rowNumber : null,
            'import_batch_id' => $batchId,
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        try {
            // Jalali "1405/08/30" or Gregorian.
            if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}/', $value)) {
                return Jalali::parse($value)?->toDateTimeString();
            }

            return Date::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
