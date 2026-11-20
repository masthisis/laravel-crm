<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
});

it('blocks panel access for deactivated users while active users pass', function () {
    $agent = User::factory()->create();
    $agent->assignRole(Roles::SALES_STAFF);

    expect($agent->canAccessPanel(filament()->getPanel('agent')))->toBeTrue();

    $agent->update(['is_active' => false]);

    expect($agent->refresh()->canAccessPanel(filament()->getPanel('agent')))->toBeFalse();
});

it('excludes deactivated agents from the shared agent selects', function () {
    $active = User::factory()->create(['name' => 'Active Agent']);
    $active->assignRole(Roles::SALES_STAFF);

    $inactive = User::factory()->create(['name' => 'Gone Agent', 'is_active' => false]);
    $inactive->assignRole(Roles::SALES_STAFF);

    $admin = User::factory()->create(['name' => 'Admin User']);
    $admin->assignRole(Roles::ADMIN);

    $names = User::activeAgents()->pluck('id');

    expect($names)->toContain($active->id)
        ->and($names)->not->toContain($inactive->id)
        ->and($names)->not->toContain($admin->id);
});

it('returns deactivated agents open leads to the pool', function () {
    $departing = User::factory()->create();
    $departing->assignRole(Roles::SALES_STAFF);

    $openLead = Lead::create([
        'name' => 'Open lead',
        'phone' => '+15550100',
        'source_id' => $this->source->id,
        'assigned_agent_id' => $departing->id,
        'assigned_at' => now(),
    ]);

    Lead::create([
        'name' => 'Won lead',
        'phone' => '+15550101',
        'source_id' => $this->source->id,
        'assigned_agent_id' => $departing->id,
        'status' => LeadStatus::Won,
    ]);

    // "Return to pool" path used by the deactivate action.
    $departing->agentLeads()->open()->get()
        ->each(fn (Lead $lead) => $lead->update(['assigned_agent_id' => null, 'assigned_at' => null]));

    $departing->update(['is_active' => false]);

    expect($openLead->refresh()->assigned_agent_id)->toBeNull()
        ->and(Lead::where('assigned_agent_id', $departing->id)->count())->toBe(1); // only the won lead stays attributed
});
