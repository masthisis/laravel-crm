<?php

use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\LeadContactSource;
use App\Models\LeadEntry;
use App\Models\LeadSource;
use App\Services\Leads\CaptureResult;
use App\Services\Leads\LeadCaptureService;
use App\Services\Leads\LegacyUserLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;

uses(RefreshDatabase::class);

/** Capture semantics (spec 05 §3). */
beforeEach(function () {
    $this->service = new LeadCaptureService(new LegacyUserLinker);
});

it('creates a contact, entry, facet and per-source summary', function () {
    $source = LeadSource::factory()->create([
        'driver' => 'stored',
        'config' => ['facets' => ['category', 'verified']],
    ]);

    $result = $this->service->capture($source, [
        'phone' => '09121234567',
        'name' => 'سارا',
        'origin_ref' => '501',
        'payload' => ['category' => 'درخواست مشاوره', 'verified' => 0],
    ]);

    expect($result->status)->toBe(CaptureResult::CAPTURED)
        ->and($result->phone)->toBe('9121234567');

    $contact = LeadContact::query()->where('phone', '9121234567')->firstOrFail();
    $entry = LeadEntry::query()->findOrFail($result->entryId);

    expect($entry->contact_id)->toBe($contact->id)
        ->and($entry->origin_ref)->toBe('501')
        ->and($entry->returning)->toBeFalse()
        ->and($entry->payload['category'])->toBe('درخواست مشاوره');

    expect($entry->facets()->count())->toBe(2)
        ->and($entry->facets()->where('field', 'category')->first()?->value)->toBe('درخواست مشاوره');

    $summary = LeadContactSource::query()->where('contact_id', $contact->id)->firstOrFail();
    expect($summary->entries)->toBe(1);
});

it('is idempotent on (source, origin_ref)', function () {
    $source = LeadSource::factory()->create(['driver' => 'stored']);

    $first = $this->service->capture($source, ['phone' => '9121234567', 'origin_ref' => '9']);
    $second = $this->service->capture($source, ['phone' => '9121234567', 'origin_ref' => '9']);

    expect($second->status)->toBe(CaptureResult::ALREADY_CAPTURED)
        ->and(LeadEntry::query()->count())->toBe(1)
        ->and($first->entryId)->not->toBeNull();
});

it('marks a re-submission from the same source as returning', function () {
    $source = LeadSource::factory()->create(['driver' => 'stored']);

    $this->service->capture($source, ['phone' => '9121234567', 'origin_ref' => '1']);
    $second = $this->service->capture($source, ['phone' => '9121234567', 'origin_ref' => '2']);

    expect($second->status)->toBe(CaptureResult::RETURNING);

    $contact = LeadContact::query()->where('phone', '9121234567')->firstOrFail();
    $summary = LeadContactSource::query()->where('contact_id', $contact->id)->firstOrFail();

    expect($summary->entries)->toBe(2);

    $entry = LeadEntry::query()->findOrFail($second->entryId);
    expect($entry->returning)->toBeTrue();
});

it('keeps invalid phones as flagged entries without a contact', function () {
    $source = LeadSource::factory()->create(['driver' => 'stored']);

    $result = $this->service->capture($source, ['phone' => '2121234567', 'name' => 'X', 'origin_ref' => '5']);

    expect($result->status)->toBe(CaptureResult::INVALID_PHONE);

    $entry = LeadEntry::query()->findOrFail($result->entryId);
    expect($entry->contact_id)->toBeNull()
        ->and($entry->invalid_phone)->toBeTrue()
        ->and($entry->raw_phone)->toBe('2121234567')
        ->and(LeadContact::query()->count())->toBe(0);
});

it('refuses capture into non-stored sources', function () {
    $segment = LeadSource::factory()->create(['driver' => 'segment']);
    $utm = LeadSource::factory()->create(['driver' => 'utm']);

    expect($this->service->capture($segment, ['phone' => '9121234567'])->status)->toBe(CaptureResult::REFUSED)
        ->and($this->service->capture($utm, ['phone' => '9121234567'])->status)->toBe(CaptureResult::REFUSED)
        ->and(LeadEntry::query()->count())->toBe(0);
});

it('links the site account across phone columns by priority', function () {
    Customer::query()->create(['name' => 'Ali', 'phone' => '9998887771', 'phone2' => '9121234567']);
    Customer::query()->create(['name' => 'Primary', 'phone' => '9121234567']);

    $source = LeadSource::factory()->create(['driver' => 'stored']);
    $this->service->capture($source, ['phone' => '09121234567']);

    $contact = LeadContact::query()->where('phone', '9121234567')->firstOrFail();
    expect($contact->customer_id)->not->toBeNull()
        ->and($contact->customer->name)->toBe('Primary'); // phone column wins over phone2
});

it('caches an account-lookup miss for 24 hours', function () {
    $source = LeadSource::factory()->create(['driver' => 'stored']);
    $this->service->capture($source, ['phone' => '9121234567']);

    $contact = LeadContact::query()->where('phone', '9121234567')->firstOrFail();
    expect($contact->customer_id)->toBeNull()->and($contact->customer_checked_at)->not->toBeNull();

    // The account appears afterwards, but within the miss window.
    Customer::query()->create(['name' => 'Late', 'phone' => '9121234567']);

    $linked = (new LegacyUserLinker)->linkMissing([$contact->fresh() ?? $contact]);
    expect($linked)->toBe(0);

    Date::setTestNow(now()->addHours(25));
    $linked = (new LegacyUserLinker)->linkMissing([LeadContact::query()->findOrFail($contact->id)]);
    expect($linked)->toBe(1);

    Date::setTestNow();
});

it('widens first and last seen without losing the existing name', function () {
    $source = LeadSource::factory()->create(['driver' => 'stored']);

    $this->service->capture($source, [
        'phone' => '9121234567', 'name' => 'Ali', 'origin_ref' => '1',
        'captured_at' => now()->subDays(2),
    ]);
    $this->service->capture($source, [
        'phone' => '9121234567', 'name' => 'Reza', 'origin_ref' => '2',
    ]);

    $contact = LeadContact::query()->where('phone', '9121234567')->firstOrFail();
    expect($contact->name)->toBe('Ali')
        ->and($contact->first_seen_at->lt($contact->last_seen_at))->toBeTrue();
});
