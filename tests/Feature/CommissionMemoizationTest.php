<?php

use App\Models\CommissionRule;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);

    CommissionRule::create(['name' => 'Blog standard', 'original_source_id' => $this->source->id, 'rate' => 15, 'priority' => 10]);
    CommissionRule::create(['name' => 'Default rate', 'rate' => 10, 'priority' => 0]);
});

it('loads the commission rules exactly once per request, regardless of lead count', function () {
    Lead::factory()->count(10)->create(['source_id' => $this->source->id]);

    $service = app(CommissionService::class);

    $ruleQueries = 0;
    DB::listen(function ($query) use (&$ruleQueries) {
        if (str_contains($query->sql, 'commission_rules')) {
            $ruleQueries++;
        }
    });

    Lead::query()->limit(10)->get()->each(fn (Lead $lead) => $service->estimateForLead($lead));

    expect($ruleQueries)->toBe(1)
        // and the memoized instance is shared within the same request:
        ->and(app(CommissionService::class))->toBe($service);
});

it('resets memoized rules when state is cleared', function () {
    $service = app(CommissionService::class);

    $lead = Lead::factory()->create(['source_id' => $this->source->id]);

    expect($service->estimateForLead($lead))->not->toBeNull();

    // New rule with higher priority appears AFTER the memoization.
    CommissionRule::create(['name' => 'Blog premium', 'original_source_id' => $this->source->id, 'rate' => 25, 'priority' => 50]);

    // Memoized instance still resolves with the old rule set…
    expect($service->estimateForLead($lead)['rule']->name)->toBe('Blog standard');

    // …but after a reset it picks up the new rule.
    $service->resetState();

    expect($service->estimateForLead($lead)['rule']->name)->toBe('Blog premium');
});
