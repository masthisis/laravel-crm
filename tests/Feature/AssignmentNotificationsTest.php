<?php

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use App\Services\LeadAssignmentService;
use App\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\assertDatabaseCount;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    $this->agent = User::factory()->create();
    $this->agent->assignRole(Roles::SALES_STAFF);
    $this->service = app(LeadAssignmentService::class);
});

it('notifies the agent once when a batch of leads is assigned to them', function () {
    $leads = Lead::factory()->count(3)->create([
        'source_id' => $this->source->id,
    ]);

    $this->service->assignAll($leads, $this->agent);

    // One summary notification, not one per lead.
    assertDatabaseCount('notifications', 1);

    $notification = $this->agent->notifications()->first();

    expect($notification->data['title'] ?? null)->toBe('You have 3 new leads');
});

it('notifies each agent with their share when leads are distributed', function () {
    $secondAgent = User::factory()->create();
    $secondAgent->assignRole(Roles::SALES_STAFF);

    $leads = Lead::factory()->count(4)->create([
        'source_id' => $this->source->id,
    ]);

    $this->service->distribute($leads, [$this->agent->id, $secondAgent->id]);

    // Exactly one notification per agent that received leads.
    assertDatabaseCount('notifications', 2);

    $titles = [
        $this->agent->notifications()->first()?->data['title'],
        $secondAgent->notifications()->first()?->data['title'],
    ];

    expect($titles)->toContain('You have 2 new leads');
});

it('sends the follow-up digest only to agents with due follow-ups', function () {
    $withDue = Lead::create([
        'name' => 'Due',
        'phone' => '+15550100',
        'source_id' => $this->source->id,
        'assigned_agent_id' => $this->agent->id,
        'follow_up_at' => now()->subHour(),
    ]);

    $agentWithoutDue = User::factory()->create();
    $agentWithoutDue->assignRole(Roles::SALES_STAFF);

    Lead::create([
        'name' => 'Not due',
        'phone' => '+15550101',
        'source_id' => $this->source->id,
        'assigned_agent_id' => $agentWithoutDue->id,
        'follow_up_at' => now()->addDays(3),
    ]);

    Artisan::call('crm:send-followup-digest');

    expect(Artisan::output())->toContain('sent to 1 agent(s)')
        ->and($this->agent->notifications()->count())->toBe(1)
        ->and($agentWithoutDue->notifications()->count())->toBe(0);
});
