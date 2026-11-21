<?php

use App\Models\LeadEntry;
use App\Models\LeadLegacyCursor;
use App\Models\LeadSource;
use App\Services\Leads\LeadCaptureService;
use App\Services\Leads\LegacyCopyService;
use App\Services\Leads\LegacyUserLinker;
use Database\Seeders\LeadServiceSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/** Legacy copy tool (spec 05 §7) over a temporary sqlite "legacy" DB. */
beforeEach(function () {
    // Point the legacy connection at a temporary sqlite file.
    $file = database_path('testing-legacy.sqlite');
    File::delete($file);
    touch($file);

    config([
        'database.connections.legacy' => [
            'driver' => 'sqlite',
            'database' => $file,
            'prefix' => '',
        ],
        'crm.legacy.enabled' => true,
        'crm.legacy.connection' => 'legacy',
    ]);

    DB::connection('legacy')->statement('CREATE TABLE rayan_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, family TEXT, phone TEXT, phone2 TEXT, phone3 TEXT
    )');
    DB::connection('legacy')->statement('CREATE TABLE rayan_starttraining (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, phone TEXT, category TEXT, status INTEGER, user_id INTEGER, date TEXT
    )');
    DB::connection('legacy')->statement('CREATE TABLE rayan_webinar_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, webinar_id INTEGER, new_user INTEGER, created_at TEXT
    )');

    $this->service = (new LegacyCopyService)->withCapture(new LeadCaptureService(new LegacyUserLinker));
});

afterEach(function () {
    File::delete(database_path('testing-legacy.sqlite'));
});

it('copies legacy rows idempotently and resumes from the cursor', function () {
    (new LeadServiceSourceSeeder)->run();

    DB::connection('legacy')->table('rayan_starttraining')->insert([
        ['name' => 'Ali', 'phone' => '09121234567', 'category' => 'درخواست مشاوره', 'status' => 0, 'user_id' => null, 'date' => '2026-11-01 10:00:00'],
        ['name' => 'Sara', 'phone' => '9129876543', 'category' => 'لندینگ درخواست تماس', 'status' => 1, 'user_id' => 7, 'date' => '2026-11-02 11:00:00'],
        ['name' => 'Bad', 'phone' => '2121234567', 'category' => 'درخواست مشاوره', 'status' => 0, 'user_id' => null, 'date' => '2026-11-03 12:00:00'],
    ]);

    $result = $this->service->step('starttraining', 2);
    expect($result['copied'])->toBe(2)->and($result['done'])->toBeFalse();

    $result = $this->service->step('starttraining', 2);
    expect($result['copied'])->toBe(0)->and($result['done'])->toBeTrue(); // row 3: invalid phone stored flagged, not copied

    // Idempotent: another run copies nothing new.
    $result = $this->service->step('starttraining', 2);
    expect($result['copied'])->toBe(0)->and($result['done'])->toBeTrue();

    $source = LeadSource::query()->where('slug', 'starttraining')->firstOrFail();
    $entry = LeadEntry::query()->where('source_id', $source->id)->where('origin_ref', '1')->firstOrFail();
    expect($entry->contact_id)->not->toBeNull()
        ->and($entry->payload['category'])->toBe('درخواست مشاوره');

    expect(LeadEntry::query()->where('invalid_phone', true)->count())->toBe(1)
        ->and(LeadLegacyCursor::query()->where('adapter', 'starttraining')->first()?->last_origin_id)->toBe(3);
});

it('joins the legacy user for the webinar adapter', function () {
    (new LeadServiceSourceSeeder)->run();

    DB::connection('legacy')->table('rayan_users')->insert(['name' => 'Maryam', 'family' => 'Rahimi', 'phone' => '9121112223']);
    $userId = DB::connection('legacy')->table('rayan_users')->max('id');

    DB::connection('legacy')->table('rayan_webinar_users')->insert([
        ['user_id' => $userId, 'webinar_id' => 2, 'new_user' => 1, 'created_at' => '2026-11-05 09:00:00'],
    ]);

    $result = $this->service->step('webinar');
    expect($result['copied'])->toBe(1);

    $source = LeadSource::query()->where('slug', 'webinar')->firstOrFail();
    $entry = LeadEntry::query()->where('source_id', $source->id)->firstOrFail();

    expect($entry->contact_id)->not->toBeNull()
        ->and((string) $entry->payload['webinar_id'])->toBe('2')
        ->and($entry->name)->toBe('Maryam Rahimi');
});

it('refuses to run when the legacy connection is disabled', function () {
    config(['crm.legacy.enabled' => false]);

    $this->service->step('starttraining');
})->throws(RuntimeException::class, 'Legacy connection is disabled');
