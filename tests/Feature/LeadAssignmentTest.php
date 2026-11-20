<?php

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadAssignmentService;
use App\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->service = app(LeadAssignmentService::class);
});

it('assigns all leads to a single agent', function () {
    $agent = User::create(['name' => 'Agent One', 'email' => 'one@example.com', 'password' => 'password']);
    $agent->assignRole(Roles::SALES_STAFF);

    $source = LeadSource::create(['name' => 'Imported', 'slug' => 'imported']);
    $leads = Lead::factory()->count(10)->create(['source_id' => $source->id]);

    $batch = $this->service->assignAll($leads, $agent);

    expect($batch->total_leads)->toBe(10)
        ->and($batch->strategy->value)->toBe('single');

    expect(Lead::where('assigned_agent_id', $agent->id)->count())->toBe(10);
    expect(Lead::whereNotNull('assigned_at')->count())->toBe(10);
});

it('distributes leads evenly across multiple agents', function () {
    $agents = collect([
        User::create(['name' => 'Agent A', 'email' => 'a@example.com', 'password' => 'password']),
        User::create(['name' => 'Agent B', 'email' => 'b@example.com', 'password' => 'password']),
    ])->each(fn ($u) => $u->assignRole(Roles::SALES_STAFF));

    $source = LeadSource::create(['name' => 'Imported 2', 'slug' => 'imported-2']);
    $leads = Lead::factory()->count(10)->create(['source_id' => $source->id]);

    $batch = $this->service->distribute($leads, $agents->pluck('id')->all());

    $counts = Lead::query()
        ->whereIn('assigned_agent_id', $agents->pluck('id'))
        ->selectRaw('assigned_agent_id, count(*) as total')
        ->groupBy('assigned_agent_id')
        ->pluck('total', 'assigned_agent_id');

    expect($counts->get($agents[0]->id))->toBe(5)
        ->and($counts->get($agents[1]->id))->toBe(5)
        ->and($batch->distribution)->toBe([$agents[0]->id => 5, $agents[1]->id => 5]);
});

it('distributes with a remainder fairly (11 leads across 2 agents)', function () {
    $agents = collect([
        User::create(['name' => 'Agent C', 'email' => 'c@example.com', 'password' => 'password']),
        User::create(['name' => 'Agent D', 'email' => 'd@example.com', 'password' => 'password']),
    ])->each(fn ($u) => $u->assignRole(Roles::SALES_STAFF));

    $source = LeadSource::create(['name' => 'Imported 3', 'slug' => 'imported-3']);
    $leads = Lead::factory()->count(11)->create(['source_id' => $source->id]);

    $this->service->distribute($leads, $agents->pluck('id')->all());

    $counts = Lead::query()
        ->selectRaw('assigned_agent_id, count(*) as total')
        ->groupBy('assigned_agent_id')
        ->pluck('total', 'assigned_agent_id');

    expect($counts->get($agents[0]->id))->toBe(6)
        ->and($counts->get($agents[1]->id))->toBe(5);
});
