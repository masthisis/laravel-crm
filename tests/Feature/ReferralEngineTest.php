<?php

use App\Models\CrmReferral;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\LeadEntry;
use App\Models\LeadEvent;
use App\Models\LeadReferralContext;
use App\Models\LeadSource;
use App\Models\Order;
use App\Models\User;
use App\Services\Leads\ConversionReportService;
use App\Services\Leads\EngagementService;
use App\Services\Leads\LeadCaptureService;
use App\Services\Leads\LegacyUserLinker;
use App\Services\Leads\ReferralService;
use App\Support\Jalali;
use App\Support\PhoneNormalizer;
use App\Support\Roles;
use Database\Seeders\LeadServiceSourceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Assignment engine (ارجاع), engagement tags, conversion report (05 §5, §8–10). */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    (new LeadServiceSourceSeeder)->run();

    $this->capture = new LeadCaptureService(new LegacyUserLinker);
    $this->referrals = app(ReferralService::class);
    $this->engagement = $this->engagement ?? app(EngagementService::class);

    $this->admin = User::factory()->create(['name' => 'Admin']);
    $this->admin->assignRole(Roles::SUPER_ADMIN);

    $this->agent107 = User::factory()->create(['name' => 'Agent 107', 'exten' => '107']);
    $this->agent109 = User::factory()->create(['name' => 'Agent 109', 'exten' => '109']);
});

function captureEntry(LeadCaptureService $capture, string $sourceSlug, string $phone, string $name, ?string $ref = null): LeadEntry
{
    $result = $capture->capture($sourceSlug, [
        'phone' => $phone, 'name' => $name, 'origin_ref' => $ref ?? 'r-'.PhoneNormalizer::canonical($phone).'-'.Str::random(4),
    ]);

    expect($result->isCaptured())->toBeTrue($result->status);

    return LeadEntry::query()->findOrFail($result->entryId);
}

it('refuses ineligible operators', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();
    dump(['source' => $source?->slug, 'count' => LeadSource::count()]);
    $entry = captureEntry($this->capture, 'starttraining', '9121234567', 'Ali');
    dump(['entry' => $entry->id, 'source' => $source?->id]);

    $inactive = User::factory()->create(['exten' => '111', 'is_active' => false]);

    $this->referrals->assign($source, [$entry->id], [$inactive->id]);
})->throws(RuntimeException::class, 'No eligible operators');

it('asks for confirmation within the 30-day window, then assigns with force', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();
    $entry = captureEntry($this->capture, 'starttraining', '9121234567', 'Ali');

    // First assignment to agent 107.
    $this->referrals->assign($source, [$entry->id], [$this->agent107->id], 'single', 'پیگیری اول', true, $this->admin->id);

    // Re-capture + re-assign within 30 days → confirm.
    $entry2 = captureEntry($this->capture, 'starttraining', '9121234567', 'Ali', 'r-again');
    $result = $this->referrals->assign($source, [$entry2->id], [$this->agent109->id]);

    expect($result['status'])->toBe('confirm')
        ->and($result['count'])->toBe(1)
        ->and($result['duplicates'][0]['operator'])->toBe('Agent 107')
        ->and($result['duplicates'][0]['phone'])->toBe('9121234567');

    // Force → assigned to 109.
    $result = $this->referrals->assign($source, [$entry2->id], [$this->agent109->id], 'single', null, true, $this->admin->id);

    expect($result['status'])->toBe('assigned')
        ->and($result['assigned'])->toBe(1)
        ->and($result['by_operator'])->toBe(['109' => 1]);

    // Both referrals exist open (11 #21: re-referral does not close the first).
    expect(CrmReferral::query()->where('reagent_code', '107')->where('status', '1')->count())->toBe(1)
        ->and(CrmReferral::query()->where('reagent_code', '109')->where('status', '1')->count())->toBe(1);

    // Contact owner = latest assignment.
    $contact = LeadContact::query()->where('phone', '9121234567')->first();
    expect($contact->owner_code)->toBe('109')
        ->and($contact->owner_operator_id)->toBe($this->agent109->id);
});

it('distributes round robin in the chosen operator order', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();

    $entries = [];
    foreach (['9121111111', '9122222222', '9123333333'] as $i => $phone) {
        $entries[] = captureEntry($this->capture, 'starttraining', $phone, "Lead $i", 'rr-'.$phone)->id;
    }

    $result = $this->referrals->assign($source, $entries, [$this->agent107->id, $this->agent109->id], 'round_robin', null, true);

    expect($result['by_operator'])->toBe(['107' => 2, '109' => 1]);
});

it('resolves the lead type from the source map', function () {
    $source = LeadSource::query()->where('slug', 'lead_from_admin')->first();
    $entry = $this->capture->capture('lead_from_admin', [
        'phone' => '9121234567', 'name' => 'Ali', 'origin_ref' => 'lt-1',
        'payload' => ['lead_type' => 'وبینار'],
    ]);

    $result = $this->referrals->assign($source, [$entry->entryId], [$this->agent107->id], 'single', null, true);
    expect($result['status'])->toBe('assigned');

    $referral = CrmReferral::query()->where('entry_id', $entry->entryId)->firstOrFail();
    expect($referral->lead_type)->toBe('lead_webinar')
        ->and($referral->reagent_code)->toBe('107')
        ->and($referral->status)->toBe('1');
});

it('freezes the engagement context at referral time', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();

    // Entry 10 days ago + a second entry today.
    $this->capture->capture('starttraining', ['phone' => '9121234567', 'name' => 'Ali', 'origin_ref' => 'e1', 'captured_at' => now()->subDays(10)]);
    $entry = captureEntry($this->capture, 'starttraining', '9121234567', 'Ali', 'e2');

    $this->referrals->assign($source, [$entry->id], [$this->agent107->id], 'single', null, true);

    $referral = CrmReferral::query()->latest('id')->firstOrFail();
    $context = $referral->context;

    expect($context)->not->toBeNull()
        ->and($context->status)->toBe(LeadReferralContext::STATUS_COMPLETE)
        ->and($context->first_label)->toBe('درخواست مشاوره')
        ->and($context->latest_label)->toBe('درخواست مشاوره')
        ->and($context->first_at->lt($context->latest_at))->toBeTrue()
        ->and($context->trigger_ref)->toBe('entry:'.$entry->id);
});

it('records the reject event and closes the referral with ownership enforced', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();
    $entry = captureEntry($this->capture, 'starttraining', '9121234567', 'Ali');
    $this->referrals->assign($source, [$entry->id], [$this->agent107->id], 'single', null, true);

    $referral = CrmReferral::query()->firstOrFail();

    // Another operator cannot close it (11 #5 fix).
    $this->referrals->reject($referral, '62,63,64', 'عدم پاسخگویی', $this->agent109);
})->throws(RuntimeException::class, 'not assigned to you');

it('closes the referral as عدم خرید with course and reason', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();
    $entry = captureEntry($this->capture, 'starttraining', '9121234567', 'Ali');
    $this->referrals->assign($source, [$entry->id], [$this->agent107->id], 'single', null, true);

    $referral = CrmReferral::query()->firstOrFail();
    $this->referrals->reject($referral, '62,63,64', 'عدم پاسخگویی', $this->agent107);

    $referral = $referral->fresh();
    expect($referral->status)->toBe('2')
        ->and($referral->course)->toBe('62,63,64')
        ->and($referral->agent_description)->toBe('عدم پاسخگویی')
        ->and(LeadEvent::query()->where('type', 'reject')->where('ref_id', $referral->id)->count())->toBe(1);
});

it('records the snooze event on a reminder', function () {
    $contact = LeadContact::create(['phone' => '9121234567', 'name' => 'Ali']);
    $until = now()->addDays(3);
    $this->referrals->trackSnooze($contact, $this->agent107, $until);

    $event = LeadEvent::query()->where('type', 'snooze')->firstOrFail();
    expect($event->contact_id)->toBe($contact->id)
        ->and($event->operator_code)->toBe('107')
        ->and($event->until_at->format('Y-m-d H:i'))->toBe($until->format('Y-m-d H:i'));
});

it('builds the conversion report for both views', function () {
    $source = LeadSource::query()->where('slug', 'starttraining')->first();

    // Contact A: referred to 107, bought after the referral.
    $entryA = captureEntry($this->capture, 'starttraining', '9121111111', 'A', 'conv-a');
    $this->referrals->assign($source, [$entryA->id], [$this->agent107->id], 'single', null, true, $this->admin->id);

    $buyerA = Customer::query()->create(['name' => 'A', 'phone' => '9121111111']);

    // Contact B: referred to 107 then 109, bought — owner view credits 109.
    $entryB = captureEntry($this->capture, 'starttraining', '9122222222', 'B', 'conv-b');
    $this->referrals->assign($source, [$entryB->id], [$this->agent107->id], 'single', null, true, $this->admin->id);
    $entryB2 = captureEntry($this->capture, 'starttraining', '9122222222', 'B', 'conv-b2');
    $this->referrals->assign($source, [$entryB2->id], [$this->agent109->id], 'single', null, true, $this->admin->id);

    $buyerB = Customer::query()->create(['name' => 'B', 'phone' => '9122222222']);

    // Purchases.
    Order::query()->create([
        'customer_id' => $buyerA->id, 'amount' => 100, 'sold_at' => now(),
    ]);
    Order::query()->create([
        'customer_id' => $buyerB->id, 'amount' => 100, 'sold_at' => now(),
    ]);

    $range = ['from' => now()->subDays(7)->startOfDay(), 'to' => now()->endOfDay()];
    $purchaseRange = ['from' => now()->subDays(7)->startOfDay(), 'to' => now()->endOfDay()];

    $report = app(ConversionReportService::class)->report($range, $purchaseRange, ['kind' => 'order'], 'history', 'agent');

    $byAgent = collect($report)->keyBy('label');

    // History view: contact B credits BOTH agents (107 and 109 were referred before).
    expect($byAgent['Agent 107']['referred'])->toBe(2)
        ->and($byAgent['Agent 107']['converted'])->toBe(2)
        ->and($byAgent['Agent 107']['buyers'])->toBe(2)
        ->and($byAgent['Agent 109']['referred'])->toBe(1)
        ->and($byAgent['Agent 109']['converted'])->toBe(1)
        ->and($byAgent['Agent 107']['rate'])->toBe(100.0);

    $owner = app(ConversionReportService::class)->report($range, $purchaseRange, ['kind' => 'order'], 'owner', 'agent');
    $byAgentOwner = collect($owner)->keyBy('label');

    // Owner view: contact B's owner at purchase = 109 only; contact A stays 107's.
    expect($byAgentOwner['Agent 107']['converted'])->toBe(1)
        ->and($byAgentOwner['Agent 109']['converted'])->toBe(1);
});

it('renders Jalali month keys for the report grouping', function () {
    expect(Jalali::monthKey(now()->setDate(2026, 11, 22)))->toBe('1405-09');
});
