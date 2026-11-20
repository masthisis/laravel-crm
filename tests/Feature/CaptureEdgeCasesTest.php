<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadIntegration;
use App\Models\LeadSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Re-engagement matrix (CaptureService):
| - open lead + same source        -> idempotent duplicate response
| - open lead + different source   -> re-engaged (source chain updated)
| - WON lead + any source          -> brand-new lead (repeat purchase)
| - LOST lead + any source         -> brand-new lead (fresh chance)
| - phone matching is EXACT        -> formatting differences create new leads
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->blog = LeadSource::create(['name' => 'Blog', 'slug' => 'blog']);
    $this->ads = LeadSource::create(['name' => 'Ads', 'slug' => 'ads']);

    $this->blogIntegration = LeadIntegration::create([
        'lead_source_id' => $this->blog->id,
        'driver' => 'webhook',
        'token' => hash('sha256', 'edge-blog'),
        'is_active' => true,
    ]);
    $this->adsIntegration = LeadIntegration::create([
        'lead_source_id' => $this->ads->id,
        'driver' => 'webhook',
        'token' => hash('sha256', 'edge-ads'),
        'is_active' => true,
    ]);
});

it('creates a fresh lead when a WON lead returns (repeat purchase, not re-engagement)', function () {
    Lead::create([
        'name' => 'Repeat Buyer',
        'phone' => '+15550401',
        'source_id' => $this->blog->id,
        'status' => LeadStatus::Won,
        'sold_amount' => 500,
    ]);

    $this->postJson('/api/leads', ['name' => 'Repeat Buyer', 'phone' => '+15550401'], ['X-Integration-Token' => 'edge-ads'])
        ->assertStatus(201);

    $leads = Lead::where('phone', '+15550401')->get();

    expect($leads)->toHaveCount(2)
        ->and($leads->pluck('status')->contains(LeadStatus::Won))->toBeTrue()
        ->and($leads->pluck('status')->contains(LeadStatus::New))->toBeTrue()
        ->and($leads->firstWhere('status', LeadStatus::New)->source_id)->toBe($this->ads->id);
});

it('creates a fresh lead when a LOST lead returns (fresh chance, not re-engagement)', function () {
    Lead::create([
        'name' => 'Lost Cause',
        'phone' => '+15550402',
        'source_id' => $this->blog->id,
        'status' => LeadStatus::Lost,
        'lost_reason' => 'Went with a competitor',
    ]);

    $this->postJson('/api/leads', ['name' => 'Lost Cause', 'phone' => '+15550402'], ['X-Integration-Token' => 'edge-ads'])
        ->assertStatus(201);

    expect(Lead::where('phone', '+15550402')->count())->toBe(2)
        ->and(Lead::where('phone', '+15550402')->where('status', LeadStatus::Lost)->exists())->toBeTrue()
        ->and(Lead::where('phone', '+15550402')->where('status', LeadStatus::New)->exists())->toBeTrue();
});

it('matches phones exactly — different formatting creates a new lead (documented behaviour)', function () {
    Lead::create([
        'name' => 'Formatted One Way',
        'phone' => '+15550403',
        'source_id' => $this->blog->id,
    ]);

    // Same human, different formatting: current engine matches exactly.
    $this->postJson('/api/leads', ['name' => 'Formatted One Way', 'phone' => '+1 555 0403'], ['X-Integration-Token' => 'edge-blog'])
        ->assertStatus(201);

    expect(Lead::count())->toBe(2);

    // Identical formatting from the same source: idempotent duplicate.
    $this->postJson('/api/leads', ['name' => 'Formatted One Way', 'phone' => '+1 555 0403'], ['X-Integration-Token' => 'edge-blog'])
        ->assertStatus(200)
        ->assertJsonPath('data.duplicate', true);

    expect(Lead::count())->toBe(2);
});
