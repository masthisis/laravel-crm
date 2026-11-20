<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    $this->agent = User::factory()->create();
});

it('dueFollowUps returns open leads with a past or now follow-up only', function () {
    $due = Lead::create(['name' => 'Due', 'phone' => '+15550001', 'source_id' => $this->source->id, 'follow_up_at' => now()->subHour()]);
    $scheduledAhead = Lead::create(['name' => 'Ahead', 'phone' => '+15550002', 'source_id' => $this->source->id, 'follow_up_at' => now()->addDay()]);
    $noFollowUp = Lead::create(['name' => 'None', 'phone' => '+15550003', 'source_id' => $this->source->id]);
    $won = Lead::create(['name' => 'Won', 'phone' => '+15550004', 'source_id' => $this->source->id, 'status' => LeadStatus::Won, 'follow_up_at' => now()->subHour()]);

    $ids = Lead::dueFollowUps()->pluck('id');

    expect($ids)->toContain($due->id)
        ->and($ids)->not->toContain($scheduledAhead->id)
        ->and($ids)->not->toContain($noFollowUp->id)
        ->and($ids)->not->toContain($won->id);
});

it('stale returns open leads with no recent activity, and excludes fresh or active ones', function () {
    config(['crm.leads.stale_days' => 7]);

    $stale = Lead::create(['name' => 'Stale', 'phone' => '+15550011', 'source_id' => $this->source->id]);
    $stale->forceFill(['created_at' => now()->subDays(10)])->save();
    $fresh = Lead::create(['name' => 'Fresh', 'phone' => '+15550012', 'source_id' => $this->source->id]);
    $fresh->forceFill(['created_at' => now()->subDays(10)])->save();
    $fresh->activities()->create(['agent_id' => $this->agent->id, 'type' => 'call', 'notes' => 'Called', 'occurred_at' => now()->subDays(2)]);
    $tooNew = Lead::create(['name' => 'Too new', 'phone' => '+15550013', 'source_id' => $this->source->id, 'created_at' => now()]);
    $staleWon = Lead::create(['name' => 'Won anyway', 'phone' => '+15550014', 'source_id' => $this->source->id, 'status' => LeadStatus::Won]);
    $staleWon->forceFill(['created_at' => now()->subDays(30)])->save();

    $ids = Lead::stale()->pluck('id');

    expect($ids)->toContain($stale->id)
        ->and($ids)->not->toContain($fresh->id)
        ->and($ids)->not->toContain($tooNew->id)
        ->and($ids)->not->toContain($staleWon->id);
});

it('assignedToday only counts leads handed over today', function () {
    $today = Lead::create(['name' => 'Today', 'phone' => '+15550021', 'source_id' => $this->source->id, 'assigned_agent_id' => $this->agent->id, 'assigned_at' => now()]);
    $yesterday = Lead::create(['name' => 'Yesterday', 'phone' => '+15550022', 'source_id' => $this->source->id, 'assigned_agent_id' => $this->agent->id, 'assigned_at' => now()->subDay()]);
    $otherAgent = User::factory()->create();
    $someoneElses = Lead::create(['name' => 'Other', 'phone' => '+15550023', 'source_id' => $this->source->id, 'assigned_agent_id' => $otherAgent->id, 'assigned_at' => now()]);

    $ids = Lead::assignedToday($this->agent->id)->pluck('id');

    expect($ids)->toContain($today->id)
        ->and($ids)->not->toContain($yesterday->id)
        ->and($ids)->not->toContain($someoneElses->id);
});

it('keeps a scheduled follow-up visible until it is done or the lead closes', function () {
    $lead = Lead::create(['name' => 'Persist', 'phone' => '+15550031', 'source_id' => $this->source->id, 'follow_up_at' => now()->subDays(3)]);

    expect(Lead::dueFollowUps()->pluck('id'))->toContain($lead->id);

    $lead->update(['status' => LeadStatus::Lost, 'follow_up_at' => null]);

    expect(Lead::dueFollowUps()->count())->toBe(0);
});
