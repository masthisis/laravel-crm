<?php

use App\Http\Controllers\Api\EventIngestController;
use App\Models\CrmCallLog;
use App\Models\CrmInvoice;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\LeadEntry;
use Database\Seeders\LeadServiceSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Post a raw JSON body with a valid HMAC signature (body bytes must match). */
function postSigned($test, array $event, string $secret = 'test-secret')
{
    $body = json_encode($event, JSON_UNESCAPED_UNICODE);

    return $test->call('POST', '/api/events', [], [], [], [
        'HTTP_X-Signature' => EventIngestController::signature($body, $secret),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

/** Event intake (spec 03 §1–3): HMAC receiver + per-type handlers. */
beforeEach(function () {
    (new LeadServiceSourceSeeder)->run();
    config(['crm.events.secret' => 'test-secret']);
});

function envelope(string $type, string $source, int $originId, array $data, ?string $eventId = null): array
{
    return [
        'event_id' => $eventId ?? (string) Str::uuid(),
        'type' => $type,
        'occurred_at' => '2026-11-20T10:00:00+03:30',
        'producer' => 'legacy-site',
        'source' => $source,
        'origin_table' => 'rayan_'.$source,
        'origin_id' => $originId,
        'idempotency_key' => $source.':'.$originId,
        'data' => $data,
    ];
}

it('rejects a request with a bad HMAC signature', function () {
    $response = $this->postJson('/api/events', envelope('lead.captured', 'starttraining', 1, [
        'phone_raw' => '09121234567', 'phone' => '9121234567', 'name' => 'Ali',
    ]), ['X-Signature' => 'sha256=deadbeef']);

    $response->assertStatus(401);
    expect(LeadEntry::query()->count())->toBe(0);
});
it('accepts a signed lead.captured event idempotently', function () {
    $event = envelope('lead.captured', 'starttraining', 501, [
        'phone_raw' => '۰۹۱۲۱۲۳۴۵۶۷', 'phone' => '9121234567', 'name' => 'Ali',
        'sub_source' => 'درخواست مشاوره مقالات',
    ]);

    postSigned($this, $event)
        ->assertStatus(202)
        ->assertJsonPath('result', 'captured');

    $contact = LeadContact::query()->where('phone', '9121234567')->firstOrFail();
    $entry = LeadEntry::query()->where('origin_ref', '501')->firstOrFail();

    expect($entry->contact_id)->toBe($contact->id)
        ->and($entry->payload['sub_source'])->toBe('درخواست مشاوره مقالات');

    // Same event_id replay → duplicate_event, no new entry.
    postSigned($this, $event)
        ->assertStatus(200)
        ->assertJsonPath('result', 'duplicate_event');

    expect(LeadEntry::query()->count())->toBe(1);

    // A poll replay with a different event_id but the same idempotency
    // key is a no-op at the entry level.
    $poll = envelope('lead.captured', 'starttraining', 501, [
        'phone_raw' => '09121234567', 'phone' => '9121234567', 'name' => 'Ali',
    ]);
    postSigned($this, $poll)
        ->assertStatus(202)
        ->assertJsonPath('result', 'already_captured');

    expect(LeadEntry::query()->count())->toBe(1);
});

it('keeps an invalid phone flagged instead of dropping it', function () {
    postSigned($this, envelope('lead.captured', 'starttraining', 77, ['phone_raw' => '2121234567', 'name' => 'X']))
        ->assertStatus(202)
        ->assertJsonPath('result', 'invalid_phone');

    expect(LeadEntry::query()->where('invalid_phone', true)->count())->toBe(1)
        ->and(LeadContact::query()->count())->toBe(0);
});

it('propagates verification to the copied entry payload (11 #29)', function () {
    postSigned($this, envelope('lead.captured', 'starttraining', 9, ['phone_raw' => '09121234567', 'name' => 'Ali']));

    postSigned($this, envelope('lead.verified', 'starttraining', 9, ['origin_id' => 9]))
        ->assertStatus(202)
        ->assertJsonPath('result', 'updated');

    expect(LeadEntry::query()->where('origin_ref', '9')->first()->payload['verified'])->toBe(1);
});

it('mirrors invoice.paid events with gateway semantics', function () {
    $customer = Customer::query()->create(['name' => 'Buyer', 'phone' => '9121234567']);

    postSigned($this, envelope('invoice.paid', 'orders', 3001, [
        'invoice_id' => 3001,
        'user_id' => $customer->id,
        'phone' => '9121234567',
        'total' => '2500000',
        'discount' => 'vip107',
        'reagent_code' => '107',
        'pay_type' => 8,
        'status' => 2,
        'products' => [62],
    ]))
        ->assertStatus(202)
        ->assertJsonPath('result', 'paid');

    $invoice = CrmInvoice::query()->where('legacy_id', 3001)->firstOrFail();
    expect($invoice->status)->toBe(2)
        ->and($invoice->total)->toBe(2500000)
        ->and($invoice->paid_at)->not->toBeNull()
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->lines()->count())->toBe(1)
        ->and($invoice->belongsToExten('107'))->toBeTrue();
});

it('records one call row per ringing extension and completes the CDR', function () {
    // Ring group: two extensions rang for the same uniqueid.
    foreach (['107', '109'] as $exten) {
        postSigned($this, envelope('call.logged', 'pbx', 10, [
            'uniqueid' => 'call-1', 'phone' => '989121234567', 'exten' => $exten, 'direction' => 'incoming',
        ]));
    }

    expect(CrmCallLog::query()->where('uniqueid', 'call-1')->count())->toBe(2);

    postSigned($this, envelope('call.completed', 'pbx', 10, [
        'uniqueid' => 'call-1', 'duration_seconds' => 95, 'disposition' => 'ANSWERED',
    ]));

    $rows = CrmCallLog::query()->where('uniqueid', 'call-1')->get();
    expect($rows->every(fn ($r) => $r->call_time === 95))->toBeTrue()
        ->and($rows->every(fn ($r) => $r->cdr_verify))->toBeTrue()
        ->and($rows->every(fn ($r) => $r->accepted_call === $r->exten))->toBeTrue()
        ->and($rows->first()->phone)->toBe('9121234567');
});

it('links account.created events to the lead contact', function () {
    postSigned($this, envelope('lead.captured', 'starttraining', 4, ['phone_raw' => '09121234567', 'name' => 'Ali']));

    expect(LeadContact::query()->where('phone', '9121234567')->first()->customer_id)->toBeNull();

    postSigned($this, envelope('account.created', 'users', 44, [
        'user_id' => 44, 'phone' => '9121234567', 'name' => 'Ali', 'family' => 'Karimi',
        'status' => 'pending', 'add_from' => 'webinar', 'crm' => 0,
    ]));

    $customer = Customer::query()->find(44);
    expect($customer)->not->toBeNull()
        ->and($customer->account_status)->toBe('pending')
        ->and($customer->add_from)->toBe('webinar');

    $contact = LeadContact::query()->where('phone', '9121234567')->first();
    expect($contact->customer_id)->toBe(44);

    // Verification later.
    postSigned($this, envelope('account.verified', 'users', 44, ['user_id' => 44]));

    expect($customer->fresh()->account_status)->toBe('verified');
});
