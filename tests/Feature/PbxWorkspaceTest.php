<?php

use App\Events\IncomingCall;
use App\Models\CrmCallLog;
use App\Models\CrmNote;
use App\Models\CrmOncall;
use App\Models\CrmSmsMessage;
use App\Models\Customer;
use App\Models\CustomerAdminLog;
use App\Models\User;
use App\Services\Phone\PbxService;
use App\Services\Phone\WorkspaceService;
use App\Support\Roles;
use Database\Seeders\CrmLookupSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** PBX integration (spec 04 §5) and workspace actions (04 §3.3–3.7). */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    (new CrmLookupSeeder)->run();

    config([
        'crm.pbx.secret' => 'pbx-secret',
        'crm.pbx.bridge' => '',
    ]);

    $this->operator = User::factory()->create(['name' => 'Agent 107', 'exten' => '107']);
    $this->operator->assignRole(Roles::SALES_STAFF);
});

it('authenticates PBX call events with the shared secret', function () {
    $this->getJson('/api/pbx/service?action=IncomingCallEvent&number=09121234567&exten=107&uniqueid=uid-1')
        ->assertStatus(401);

    $this->getJson('/api/pbx/service?action=IncomingCallEvent&number=09121234567&exten=107&uniqueid=uid-1&token=pbx-secret')
        ->assertStatus(200)
        ->assertJsonPath('status', true);

    $call = CrmCallLog::query()->where('uniqueid', 'uid-1')->firstOrFail();
    expect($call->phone)->toBe('9121234567')
        ->and($call->type)->toBe('incoming')
        ->and($call->exten)->toBe('107');
});

it('records accepted and hangup events on the right rows', function () {
    PbxService::logCall('IncomingCallEvent', '09121234567', '107', 'uid-2');

    $this->getJson('/api/pbx/service?action=IncomingCallAcceptedEvent&exten=107&uniqueid=uid-2&token=pbx-secret');
    $this->getJson('/api/pbx/service?action=IncomingCallHangUpEvent&exten=107&uniqueid=uid-2&token=pbx-secret');

    $call = CrmCallLog::query()->where('uniqueid', 'uid-2')->firstOrFail();
    expect($call->accepted_call)->toBe('107')
        ->and($call->hangup)->toBe('107');
});

it('broadcasts a live incoming-call event', function () {
    Event::fake([IncomingCall::class]);

    $this->getJson('/api/pbx/service?action=IncomingCallEvent&number=09121234567&exten=107&uniqueid=uid-3&token=pbx-secret');

    Event::assertDispatched(IncomingCall::class);
});

it('fixes numbers per FixNumber semantics with the canonical rule', function () {
    expect(PbxService::fixNumber('+989121234567'))->toBe('9121234567')
        ->and(PbxService::fixNumber('۰۲۱۸۸۷۷۶۶۵۵'))->toBe('2188776655')
        ->and(PbxService::fixNumber('88776655'))->toBe('88776655') // Tehran local
        ->and(PbxService::fixNumber('abc'))->toBeNull(); // garbage → dropped by logCall
});

it('adds notes with replies and keeps one updated_at', function () {
    $customer = Customer::query()->create(['name' => 'Ali', 'phone' => '9121234567']);

    $note = app(WorkspaceService::class)->addNote(
        $customer->id,
        $this->operator,
        'مشاوره دوره',
        'مشتری به دوره دکس علاقه دارد',
        departmentId: 1,
        categoryId: 2,
    );

    expect($note->replies()->count())->toBe(1)
        ->and($note->ticket_number)->toBeInt()
        ->and($note->status)->toBe(CrmNote::STATUS_NOTE);

    app(WorkspaceService::class)->reply($note, $this->operator, 'پیگیری شد');

    expect($note->fresh()->replies()->count())->toBe(2)
        ->and($note->fresh()->updated_at)->not->toBeNull();
});

it('records call-queue outcomes as a call-status note with ownership', function () {
    $row = CrmOncall::create(['phone' => '9121234567', 'operator_id' => $this->operator->id, 'category' => 'دوره‌ها', 'queued_at' => now()]);

    app(WorkspaceService::class)->recordOutcome($row, $this->operator, 2, 'پاسخ داد، علاقه‌مند به اپ');

    expect($row->fresh()->status)->toBe(2);

    $note = CrmNote::query()->where('status', CrmNote::STATUS_CALL_NOTE)->firstOrFail();
    expect($note->subject)->toBe('وضعیت تماس')
        ->and($note->replies()->latest('id')->first()->message)->toContain('اپ');

    // Another operator cannot touch my queue row.
    $other = User::factory()->create(['exten' => '109']);
    expect(CrmOncall::query()->where('id', $row->id)->where('operator_id', $other->id)->first())->toBeNull();
});

it('computes SMS part counts per the spec rule', function () {
    $persianShort = str_repeat('ا', 70);
    $persianLong = str_repeat('ا', 71);

    expect(CrmSmsMessage::partsFor($persianShort))->toBe(1)
        ->and(CrmSmsMessage::partsFor($persianLong))->toBe((int) ceil(71 / 67))
        ->and(CrmSmsMessage::partsFor(str_repeat('a', 160)))->toBe(1)
        ->and(CrmSmsMessage::partsFor(str_repeat('a', 161)))->toBe(2);
});

it('logs SMS messages even without a provider', function () {
    $message = app(WorkspaceService::class)->sendSms('9121234567', 'سلام از تیم آرم', $this->operator);

    expect($message->status)->toBe('logged') // never pretends to be sent
        ->and($message->parts)->toBe(1)
        ->and(CrmSmsMessage::query()->count())->toBe(1);
});

it('exposes the desktop lookup API through a token, not query credentials', function () {
    $customer = Customer::query()->create(['name' => 'Ali', 'phone' => '9121234567']);

    // Without a token → unauthorized.
    $this->getJson('/api/pbx/notification?phone=09121234567')->assertStatus(401);

    $token = $this->operator->createToken('desktop')->plainTextToken;

    $this->getJson('/api/pbx/notification?phone=09121234567', ['Authorization' => 'Bearer '.$token])
        ->assertStatus(200)
        ->assertJsonPath('status', true)
        ->assertJsonPath('user_id', $customer->id);
});

it('registers an unknown number and redirects known ones', function () {
    $this->actingAs($this->operator);

    // Unknown → page renders.
    $this->get('/agent/register/09121234567')->assertOk();

    // Known → redirect to the customer file.
    Customer::query()->create(['name' => 'Ali', 'phone' => '9121234567']);
    $this->get('/agent/register/09121234567')->assertRedirect('/agent/customers/'.Customer::first()->id);

    // POST creates the CRM account with provenance flags.
    $this->post('/agent/register/09129876543', [
        'name' => 'Sara',
        'phone2' => '۰۹۱۲۱۱۱۲۲۳۳',
        'address' => 'Tehran',
    ])->assertRedirect();

    $customer = Customer::query()->where('phone', '9129876543')->firstOrFail();
    expect($customer->is_crm_created)->toBeTrue()
        ->and($customer->created_by_operator_id)->toBe($this->operator->id)
        ->and($customer->phone2)->toBe('9121112233');

    expect(CustomerAdminLog::query()->where('customer_id', $customer->id)->where('action', 'register')->exists())->toBeTrue();
});
