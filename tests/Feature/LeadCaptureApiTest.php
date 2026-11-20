<?php

use App\Enums\LeadStatus;
use App\Events\DuplicateCaptureIgnored;
use App\Events\LeadCaptured;
use App\Events\LeadReengaged;
use App\Models\Lead;
use App\Models\LeadIntegration;
use App\Models\LeadSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('creates a lead from an external webhook using the integration token', function () {
    $source = LeadSource::create(['name' => 'Facebook Ads', 'slug' => 'facebook-ads']);
    $integration = LeadIntegration::create(['lead_source_id' => $source->id, 'driver' => 'webhook', 'token' => hash('sha256', 'test-token-123'), 'is_active' => true]);

    $response = $this->postJson('/api/leads', [
        'name' => 'External Lead',
        'phone' => '+1000000006',
        'email' => 'external@example.com',
    ], ['X-Integration-Token' => 'test-token-123']);

    $response->assertStatus(201);

    $lead = Lead::where('phone', '+1000000006')->first();

    expect($lead)->not->toBeNull()
        ->and($lead->name)->toBe('External Lead')
        ->and($lead->source_id)->toBe($source->id)
        ->and($lead->status)->toBe(LeadStatus::New);
});

it('rejects requests with an invalid token', function () {
    $this->postJson('/api/leads', ['name' => 'X', 'phone' => '+1000000007'], ['X-Integration-Token' => 'nope'])
        ->assertStatus(404);
});

it('rejects requests to an inactive integration', function () {
    $source = LeadSource::create(['name' => 'Old form', 'slug' => 'old-form']);
    LeadIntegration::create(['lead_source_id' => $source->id, 'driver' => 'webhook', 'token' => hash('sha256', 'inactive-token'), 'is_active' => false]);

    $this->postJson('/api/leads', ['name' => 'X', 'phone' => '+1000000008'], ['X-Integration-Token' => 'inactive-token'])
        ->assertStatus(404);
});

it('flags re-engagement when an existing open lead comes back from another source', function () {
    $original = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    $ads = LeadSource::create(['name' => 'Facebook Ads', 'slug' => 'facebook-ads']);

    LeadIntegration::create(['lead_source_id' => $original->id, 'driver' => 'webhook', 'token' => hash('sha256', 'blog-token'), 'is_active' => true]);
    LeadIntegration::create(['lead_source_id' => $ads->id, 'driver' => 'webhook', 'token' => hash('sha256', 'ads-token'), 'is_active' => true]);

    $this->postJson('/api/leads', ['name' => 'Jane', 'phone' => '+1000000009'], ['X-Integration-Token' => 'blog-token'])
        ->assertStatus(201);

    $response = $this->postJson('/api/leads', ['name' => 'Jane', 'phone' => '+1000000009'], ['X-Integration-Token' => 'ads-token']);

    $response->assertStatus(200);

    $lead = Lead::where('phone', '+1000000009')->first();

    expect($lead->status)->toBe(LeadStatus::ReEngaged)
        ->and($lead->source_id)->toBe($original->id)
        ->and($lead->re_engaged_source_id)->toBe($ads->id);

    // Still only ONE lead for that phone.
    expect(Lead::where('phone', '+1000000009')->count())->toBe(1);
});

it('responds idempotently for duplicate submissions from the same source', function () {
    $source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    LeadIntegration::create(['lead_source_id' => $source->id, 'driver' => 'webhook', 'token' => hash('sha256', 'dup-token'), 'is_active' => true]);

    $this->postJson('/api/leads', ['name' => 'John', 'phone' => '+1000000010'], ['X-Integration-Token' => 'dup-token'])->assertStatus(201);
    $response = $this->postJson('/api/leads', ['name' => 'John', 'phone' => '+1000000010'], ['X-Integration-Token' => 'dup-token']);

    $response->assertStatus(200);
    expect(Lead::where('phone', '+1000000010')->count())->toBe(1);
});

it('validates required fields', function () {
    $source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form2']);
    LeadIntegration::create(['lead_source_id' => $source->id, 'driver' => 'webhook', 'token' => hash('sha256', 'valid-token'), 'is_active' => true]);

    $this->postJson('/api/leads', ['phone' => '+1000000011'], ['X-Integration-Token' => 'valid-token'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('serves the embeddable form page', function () {
    $source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form3']);
    $integration = LeadIntegration::create(['lead_source_id' => $source->id, 'driver' => 'form_embed', 'token' => hash('sha256', 'embed-token'), 'is_active' => true]);

    $this->get("/embed/leads/{$integration->id}")
        ->assertOk()
        ->assertSee('Request a call')
        ->assertSee('name="integration_id" value="'.$integration->id.'"', false);
});

it('dispatches capture, re-engagement and duplicate events', function () {
    Event::fake([
        LeadCaptured::class,
        LeadReengaged::class,
        DuplicateCaptureIgnored::class,
    ]);

    $source = LeadSource::create(['name' => 'Facebook Ads', 'slug' => 'facebook-ads']);
    $otherSource = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    $integration = LeadIntegration::create(['lead_source_id' => $source->id, 'driver' => 'webhook', 'token' => hash('sha256', 'tok-evt-1'), 'is_active' => true]);
    $otherIntegration = LeadIntegration::create(['lead_source_id' => $otherSource->id, 'driver' => 'webhook', 'token' => hash('sha256', 'tok-evt-2'), 'is_active' => true]);

    // First submission -> captured.
    $this->postJson('/api/leads', ['name' => 'Event Person', 'phone' => '+15559991'], ['X-Integration-Token' => 'tok-evt-1'])
        ->assertStatus(201);

    // Same source again -> duplicate ignored.
    $this->postJson('/api/leads', ['name' => 'Event Person', 'phone' => '+15559991'], ['X-Integration-Token' => 'tok-evt-1'])
        ->assertStatus(200);

    // Different source -> re-engaged.
    $this->postJson('/api/leads', ['name' => 'Event Person', 'phone' => '+15559991'], ['X-Integration-Token' => 'tok-evt-2'])
        ->assertStatus(200);

    Event::assertDispatchedTimes(LeadCaptured::class, 1);
    Event::assertDispatchedTimes(DuplicateCaptureIgnored::class, 1);
    Event::assertDispatchedTimes(LeadReengaged::class, 1);
});
