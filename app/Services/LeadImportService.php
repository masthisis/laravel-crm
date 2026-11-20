<?php

namespace App\Services;

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Models\AssignmentBatch;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * CSV lead importer.
 *
 * Expected header row: name, phone, email, source, estimated_value
 * (only "name" is mandatory). A per-row "source" column creates the
 * source on the fly; otherwise the selected source from the import
 * form is used.
 */
class LeadImportService
{
    public function __construct(
        protected LeadAssignmentService $assignments,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: array<int, string>, batch: AssignmentBatch|null}
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
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['File not found.'], 'batch' => null];
        }

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['File is not readable.'], 'batch' => null];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0, 'errors' => ['CSV has no header row.'], 'batch' => null];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h, "\xEF\xBB\xBF \t\"")), $header);

        $imported = 0;
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
                $errors[] = "Row {$rowNumber}: missing required 'name' column.";

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
                $errors[] = "Row {$rowNumber}: no source given.";

                continue;
            }

            $leads->push(Lead::create([
                'name' => $data['name'],
                'email' => ($data['email'] ?? '') !== '' ? $data['email'] : null,
                'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
                'source_id' => $source->id,
                'status' => LeadStatus::New,
                'estimated_value' => ($data['estimated_value'] ?? '') !== '' ? (float) $data['estimated_value'] : null,
                'meta' => ['imported' => true],
            ]));

            $imported++;
        }

        fclose($handle);

        $batch = null;

        if ($leads->isNotEmpty()) {
            $actor = User::find($actorId);
            $batch = match ($strategy) {
                AssignmentStrategy::Single => $this->assignments->assignAll($leads, User::findOrFail($singleAgentId), $actor),
                AssignmentStrategy::Distribute => $this->assignments->distribute($leads, $distributeAgentIds, $actor),
                AssignmentStrategy::None => AssignmentBatch::create([
                    'total_leads' => $leads->count(),
                    'strategy' => AssignmentStrategy::None,
                    'user_id' => $actorId,
                    'distribution' => [],
                ]),
            };
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'batch' => $batch,
        ];
    }

    public function integrationToken(): string
    {
        return hash('sha256', Str::random(32));
    }
}
