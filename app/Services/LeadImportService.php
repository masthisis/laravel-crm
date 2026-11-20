<?php

namespace App\Services;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Models\AssignmentBatch;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * CSV lead importer.
 *
 * Expected header row: name, phone, email, source, estimated_value
 * (only "name" is mandatory). A per-row "source" column creates the
 * source on the fly; otherwise the selected source from the import
 * form is used.
 *
 * Duplicate policy (crm.import.duplicate = skip|import): a row whose
 * phone already exists on an open lead is counted as a duplicate and
 * skipped by default — re-importing the same file imports nothing.
 */
class LeadImportService
{
    public function __construct(
        protected LeadAssignmentService $assignments,
    ) {}

    /**
     * @return array{imported: int, skipped: int, duplicates: int, errors: array<int, string>, batch: AssignmentBatch|null}
     */
    public function import(
        string $absolutePath,
        ?int $sourceId,
        ?string $newSourceName,
        int $actorId,
        AssignmentStrategy $strategy = AssignmentStrategy::None,
        ?int $singleAgentId = null,
        array $distributeAgentIds = [],
    ): array {
        if (! is_file($absolutePath)) {
            return ['imported' => 0, 'skipped' => 0, 'duplicates' => 0, 'errors' => ['File not found.'], 'batch' => null];
        }

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'duplicates' => 0, 'errors' => ['File is not readable.'], 'batch' => null];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0, 'duplicates' => 0, 'errors' => ['CSV has no header row.'], 'batch' => null];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \t\"")), $header);

        $duplicatePolicy = config('crm.import.duplicate', 'skip');

        $imported = 0;
        $duplicates = 0;
        $skipped = 0;
        $errors = [];
        $rowNumber = 1;
        $leads = collect();

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $data = [];
            foreach ($header as $i => $key) {
                $data[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            if (($data['name'] ?? '') === '') {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Missing required 'name' column."];

                continue;
            }

            $source = null;

            if (($data['source'] ?? '') !== '') {
                $source = CommissionService::resolveSource($data['source'], 'import');
            } elseif ($sourceId !== null) {
                $source = LeadSource::find($sourceId);
            } elseif (filled($newSourceName)) {
                $source = CommissionService::resolveSource($newSourceName, 'import');
            }

            if ($source === null) {
                $skipped++;
                $errors[] = ['row' => $rowNumber, 'reason' => 'No source given.'];

                continue;
            }

            $phone = ($data['phone'] ?? '') !== '' ? $data['phone'] : null;

            if ($phone !== null && $duplicatePolicy === 'skip' && $this->isDuplicatePhone($phone)) {
                $duplicates++;
                $errors[] = ['row' => $rowNumber, 'reason' => "Duplicate phone {$phone} — an open lead with this phone already exists."];

                continue;
            }

            $leads->push(Lead::create([
                'name' => $data['name'],
                'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
                'phone' => $phone,
                'source_id' => $source->id,
                'status' => LeadStatus::New,
                'estimated_value' => ($data['estimated_value'] ?? '') !== '' ? (float) $data['estimated_value'] : null,
                'meta' => ['imported' => true],
            ]));

            $imported++;
        }

        fclose($handle);

        $batch = $this->persistBatch([
            'filename' => basename($absolutePath),
            'total_rows' => $imported + $duplicates + $skipped,
            'imported_count' => $imported,
            'duplicates_count' => $duplicates,
            'failed_count' => $skipped,
            'errors' => $errors ?: null,
            'total_leads' => $leads->count(),
            'strategy' => $strategy,
            'single_agent_id' => $singleAgentId,
            'distribute_agent_ids' => $distributeAgentIds,
            'actor_id' => $actorId,
            'leads' => $leads,
        ]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'duplicates' => $duplicates,
            'errors' => array_map(fn ($e) => 'Row '.$e['row'].': '.$e['reason'], $errors),
            'batch' => $batch,
        ];
    }

    protected function isDuplicatePhone(string $phone): bool
    {
        return Lead::query()
            ->where('phone', $phone)
            ->open()
            ->exists();
    }

    /**
     * @param  array{filename: string, total_rows: int, imported_count: int, duplicates_count: int, failed_count: int, errors: array|null, total_leads: int, strategy: AssignmentStrategy, single_agent_id: int|null, distribute_agent_ids: array, actor_id: int, leads: Collection<int, Lead>}  $report
     */
    protected function persistBatch(array $report): ?AssignmentBatch
    {
        if ($report['total_rows'] === 0) {
            return null;
        }

        $base = [
            'filename' => $report['filename'],
            'total_rows' => $report['total_rows'],
            'imported_count' => $report['imported_count'],
            'duplicates_count' => $report['duplicates_count'],
            'failed_count' => $report['failed_count'],
            'total_leads' => $report['total_leads'],
            'user_id' => $report['actor_id'],
            'errors' => $report['errors'],
        ];

        return match ($report['strategy']) {
            AssignmentStrategy::Single => $this->withReport(
                $this->assignments->assignAll(
                    $report['leads'],
                    User::findOrFail($report['single_agent_id']),
                    User::find($report['actor_id']),
                    $report['filename'],
                ),
                $base,
            ),
            AssignmentStrategy::Distribute => $this->withReport(
                $this->assignments->distribute(
                    $report['leads'],
                    $report['distribute_agent_ids'],
                    User::find($report['actor_id']),
                    $report['filename'],
                ),
                $base,
            ),
            AssignmentStrategy::None => AssignmentBatch::create($base + [
                'strategy' => AssignmentStrategy::None,
                'distribution' => [],
            ]),
        };
    }

    /** Decorate an assignment batch with the import report counters. */
    protected function withReport(AssignmentBatch $batch, array $base): AssignmentBatch
    {
        $batch->update($base);

        return $batch;
    }

    public function integrationToken(): string
    {
        return hash('sha256', Str::random(32));
    }
}
