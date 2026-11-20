<?php

use App\Enums\AssignmentStrategy;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertDatabaseCount;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crm.import.duplicate' => 'skip']);

    $this->actor = User::factory()->create();
    $this->service = app(LeadImportService::class);

    $this->path = storage_path('framework/testing/import-'.uniqid().'.csv');
});

afterEach(function () {
    @unlink($this->path);
});

function writeCsv(string $path, array $rows): void
{
    $out = fopen($path, 'w');
    fputcsv($out, ['name', 'phone', 'email', 'source', 'estimated_value']);

    foreach ($rows as $row) {
        fputcsv($out, $row);
    }

    fclose($out);
}

it('imports rows, creates the source and persists the batch report', function () {
    LeadSource::create(['name' => 'Existing source', 'slug' => 'existing-source']);

    writeCsv($this->path, [
        ['Jane Doe', '+15550201', 'jane@example.com', 'Existing source', '500'],
        ['No Phone', '', '', 'Existing source', ''],
        ['', '+15550299', '', 'Existing source', ''],
    ]);

    $result = $this->service->import($this->path, null, null, $this->actor->id);

    expect($result['imported'])->toBe(2)
        ->and($result['duplicates'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['batch'])->not->toBeNull()
        ->and($result['batch']->total_rows)->toBe(3)
        ->and($result['batch']->imported_count)->toBe(2)
        ->and($result['batch']->failed_count)->toBe(1)
        // The persisted row-level error report keeps the row number + reason.
        ->and($result['batch']->errors)->toBe([[
            'row' => 4,
            'reason' => "Missing required 'name' column.",
        ]]);
});

it('skips duplicate phones on re-import (default policy)', function () {
    writeCsv($this->path, [
        ['Jane Doe', '+15550211', 'jane@example.com', 'New source', ''],
        ['John Doe', '+15550212', '', 'New source', ''],
    ]);

    $this->service->import($this->path, null, null, $this->actor->id);

    // Re-importing the exact same file must import nothing.
    $second = $this->service->import($this->path, null, null, $this->actor->id);

    expect($second['imported'])->toBe(0)
        ->and($second['duplicates'])->toBe(2)
        ->and(Lead::count())->toBe(2);
});

it('imports duplicates when the policy is set to import', function () {
    config(['crm.import.duplicate' => 'import']);

    writeCsv($this->path, [
        ['Jane Doe', '+15550221', '', 'New source', ''],
    ]);

    $this->service->import($this->path, null, null, $this->actor->id);
    $second = $this->service->import($this->path, null, null, $this->actor->id);

    expect($second['imported'])->toBe(1)
        ->and($second['duplicates'])->toBe(0)
        ->and(Lead::count())->toBe(2);
});

it('does not treat closed leads as duplicates', function () {
    $source = LeadSource::create(['name' => 'Existing source', 'slug' => 'existing-source']);

    Lead::create([
        'name' => 'Closed lead',
        'phone' => '+15550231',
        'source_id' => $source->id,
        'status' => LeadStatus::Lost,
    ]);

    writeCsv($this->path, [
        ['Fresh attempt', '+15550231', '', 'Existing source', ''],
    ]);

    $result = $this->service->import($this->path, null, null, $this->actor->id);

    expect($result['imported'])->toBe(1)
        ->and($result['duplicates'])->toBe(0)
        ->and($result['skipped'])->toBe(0)
        ->and(Lead::count())->toBe(2);
});

it('records assignment-driven imports with the same report counters', function () {
    writeCsv($this->path, [
        ['Jane Doe', '+15550241', '', 'New source', ''],
        ['John Doe', '+15550242', '', 'New source', ''],
    ]);

    $result = $this->service->import(
        $this->path, null, null, $this->actor->id,
        AssignmentStrategy::Distribute,
        null,
        [$this->actor->id],
    );

    expect($result['batch'])->not->toBeNull()
        ->and($result['batch']->imported_count)->toBe(2)
        ->and($result['batch']->total_rows)->toBe(2);

    assertDatabaseCount('assignment_batches', 1);
});
