<?php

use App\Enums\CommissionStatus;
use App\Enums\LeadStatus;
use App\Models\CommissionRule;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CommissionService::class);

    $this->blogForm = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    $this->facebook = LeadSource::create(['name' => 'Facebook Ads', 'slug' => 'facebook-ads']);
    $this->referral = LeadSource::create(['name' => 'Referral', 'slug' => 'referral']);
});

it('matches the exact source combination first (original + re-engaged)', function () {
    CommissionRule::create(['name' => 'Blog standard', 'original_source_id' => $this->blogForm->id, 'rate' => 15, 'priority' => 10]);
    CommissionRule::create([
        'name' => 'Blog + Facebook re-engaged',
        'original_source_id' => $this->blogForm->id,
        're_engaged_source_id' => $this->facebook->id,
        'rate' => 25,
        'priority' => 10,
    ]);

    $lead = Lead::create([
        'name' => 'Test Lead',
        'phone' => '+1000000001',
        'source_id' => $this->blogForm->id,
        're_engaged_source_id' => $this->facebook->id,
        'status' => LeadStatus::ReEngaged,
        'estimated_value' => 1000,
    ]);

    $estimate = $this->service->estimateForLead($lead);

    expect($estimate)->not->toBeNull()
        ->and($estimate['rule']->name)->toBe('Blog + Facebook re-engaged')
        ->and($estimate['rate'])->toBe(25.0)
        ->and($estimate['amount'])->toBe(250.0);
});

it('falls back to the less specific rule when there is no re-engagement', function () {
    CommissionRule::create(['name' => 'Blog standard', 'original_source_id' => $this->blogForm->id, 'rate' => 15, 'priority' => 10]);
    CommissionRule::create([
        'name' => 'Blog + Facebook re-engaged',
        'original_source_id' => $this->blogForm->id,
        're_engaged_source_id' => $this->facebook->id,
        'rate' => 25,
        'priority' => 10,
    ]);

    $lead = Lead::create([
        'name' => 'Plain Lead',
        'phone' => '+1000000002',
        'source_id' => $this->blogForm->id,
        'estimated_value' => 1000,
    ]);

    $estimate = $this->service->estimateForLead($lead);

    expect($estimate['rule']->name)->toBe('Blog standard')
        ->and($estimate['amount'])->toBe(150.0);
});

it('uses the wildcard fallback rule for unknown sources', function () {
    CommissionRule::create(['name' => 'Default rate', 'rate' => 10, 'priority' => 0]);

    $lead = Lead::create([
        'name' => 'Unknown Source Lead',
        'phone' => '+1000000003',
        'source_id' => $this->referral->id,
        'estimated_value' => 500,
    ]);

    $estimate = $this->service->estimateForLead($lead);

    expect($estimate['rule']->name)->toBe('Default rate')
        ->and($estimate['amount'])->toBe(50.0);
});

it('returns null when no rule matches and no wildcard exists', function () {
    CommissionRule::create(['name' => 'Blog standard', 'original_source_id' => $this->blogForm->id, 'rate' => 15]);

    $lead = Lead::create([
        'name' => 'No Rule Lead',
        'phone' => '+1000000004',
        'source_id' => $this->referral->id,
        'estimated_value' => 500,
    ]);

    expect($this->service->estimateForLead($lead))->toBeNull();
});

it('records a sale: lead becomes won and a pending commission is created', function () {
    CommissionRule::create(['name' => 'Blog standard', 'original_source_id' => $this->blogForm->id, 'rate' => 20]);

    $lead = Lead::create([
        'name' => 'Sale Lead',
        'phone' => '+1000000005',
        'source_id' => $this->blogForm->id,
        'status' => LeadStatus::Qualified,
        'estimated_value' => 2000,
    ]);

    $commission = $this->service->recordSale($lead, 3000);

    expect($commission)->not->toBeNull()
        ->and((string) $commission->amount)->toBe('600.00')
        ->and((string) $commission->base_amount)->toBe('3000.00')
        ->and($commission->status)->toBe(CommissionStatus::Pending)
        ->and($lead->refresh()->status)->toBe(LeadStatus::Won)
        ->and((string) $lead->sold_amount)->toBe('3000.00');

    assertDatabaseHas('commissions', ['lead_id' => $lead->id, 'amount' => 600]);

    // Calling recordSale again must not duplicate the commission.
    expect($this->service->recordSale($lead, 3000))->toBeNull();
    expect($lead->commissions()->count())->toBe(1);
});
