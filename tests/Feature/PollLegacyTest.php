<?php

use App\Models\CrmInvoice;
use App\Models\Customer;
use App\Models\LeadEntry;
use App\Services\Intake\PollLegacyService;
use Database\Seeders\LeadServiceSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/** Polling fallback (spec 03 §4) over a temporary sqlite "legacy" DB. */
beforeEach(function () {
    (new LeadServiceSourceSeeder)->run();

    $file = database_path('testing-legacy.sqlite');
    File::delete($file);
    touch($file);

    config([
        'database.connections.legacy' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => ''],
        'crm.legacy.enabled' => true,
        'crm.legacy.connection' => 'legacy',
    ]);

    $db = DB::connection('legacy');
    $db->statement('CREATE TABLE rayan_starttraining (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, phone TEXT, category TEXT, status INTEGER, date TEXT, date_update TEXT)');
    $db->statement('CREATE TABLE rayan_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, family TEXT, phone TEXT, status TEXT, add_from TEXT, crm INTEGER, operator_id INTEGER, date TEXT)');
    $db->statement('CREATE TABLE rayan_prefactor_group (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, phone TEXT, trackingcode INTEGER, total TEXT, discount TEXT, reagent_code TEXT, status INTEGER, pay_type INTEGER, date TEXT, update_date TEXT)');
    $db->statement('CREATE TABLE rayan_crm_call_log (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, phone_asterisk_cdr TEXT, exten TEXT, type TEXT, uniqueid TEXT, accepted_call TEXT, hangup TEXT, call_time INTEGER, status_asterisk TEXT, cdr_verify INTEGER, date TEXT, date_update TEXT)');

    $this->service = app(PollLegacyService::class);
});

afterEach(function () {
    File::delete(database_path('testing-legacy.sqlite'));
});

it('polls new rows by id cursor and shares idempotency with events', function () {
    DB::connection('legacy')->table('rayan_starttraining')->insert([
        ['name' => 'Ali', 'phone' => '09121234567', 'category' => 'درخواست مشاوره', 'status' => 0, 'date' => '2026-11-20 10:00:00', 'date_update' => '2026-11-20 10:00:00'],
    ]);

    $counts = $this->service->pollAll();
    expect($counts['starttraining'])->toBe(1);

    $entry = LeadEntry::query()->where('origin_ref', '1')->whereHas('source', fn ($q) => $q->where('slug', 'starttraining'))->firstOrFail();
    expect($entry->contact()->first()?->phone)->toBe('9121234567')
        ->and($entry->payload['sub_source'])->toBe('درخواست مشاوره');

    // A second pass copies nothing (cursor advanced).
    expect($this->service->pollAll()['starttraining'])->toBe(0)
        ->and(LeadEntry::query()->count())->toBe(1);
});

it('polls account.created rows and mirrors invoices', function () {
    DB::connection('legacy')->table('rayan_users')->insert([
        ['name' => 'Sara', 'family' => 'Ahmadi', 'phone' => '9129876543', 'status' => 'verified', 'add_from' => 'webinar', 'crm' => 0, 'operator_id' => null, 'date' => '2026-11-19 09:00:00'],
    ]);

    $counts = $this->service->pollAll();
    expect($counts['users'])->toBe(1);

    expect(Customer::query()->where('phone', '9129876543')->first()->account_status)->toBe('verified');

    // A paid invoice row mirrors into crm_invoices.
    DB::connection('legacy')->table('rayan_prefactor_group')->insert([
        ['user_id' => 1, 'phone' => '9129876543', 'trackingcode' => 55123, 'total' => '1,200,000', 'discount' => 'vip107', 'reagent_code' => '107', 'status' => 2, 'pay_type' => 2, 'date' => '2026-11-20 12:00:00', 'update_date' => '2026-11-20 12:30:00'],
    ]);

    $this->service->pollAll();
    $invoice = CrmInvoice::query()->where('legacy_id', 1)->firstOrFail();
    expect($invoice->isPaid())->toBeTrue()
        ->and($invoice->total)->toBe(1200000)
        ->and($invoice->tracking_code)->toBe(55123);
});

it('picks up status changes by update_date window', function () {
    // Verified after the copy — only visible through the status window.
    DB::connection('legacy')->table('rayan_starttraining')->insert([
        ['name' => 'Ali', 'phone' => '09121234567', 'category' => 'درخواست مشاوره', 'status' => 1, 'date' => '2026-11-20 10:00:00', 'date_update' => now()->subMinutes(2)->format('Y-m-d H:i:s')],
    ]);

    $counts = $this->service->pollAll();
    expect($counts['starttraining_status'])->toBe(1);

    $entry = LeadEntry::query()->where('origin_ref', '1')->firstOrFail();
    expect($entry->payload['verified'])->toBe(1);
});

it('refuses to poll when legacy is disabled', function () {
    config(['crm.legacy.enabled' => false]);
    $this->service->pollAll();
})->throws(RuntimeException::class);
