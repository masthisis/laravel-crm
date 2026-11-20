<?php

use App\Enums\ActivityType;
use App\Enums\CommissionStatus;
use App\Enums\LeadStatus;
use App\Enums\ProductType;
use App\Enums\SubscriptionStatus;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Product;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(CommissionService::class);

    $this->source = LeadSource::create(['name' => 'Blog form', 'slug' => 'blog-form']);
    $this->agent = User::factory()->create();

    CommissionRule::create(['name' => 'Default rate', 'rate' => 10, 'priority' => 0]);
});

it('creates a customer and an order when a lead is won', function () {
    $lead = Lead::create([
        'name' => 'Jane Buyer',
        'phone' => '+15550100',
        'email' => 'jane@example.com',
        'source_id' => $this->source->id,
        'estimated_value' => 500,
        'assigned_agent_id' => $this->agent->id,
    ]);

    $commission = $this->service->recordSale($lead, 500);

    expect($commission)->not->toBeNull()
        ->and($commission->status)->toBe(CommissionStatus::Pending);

    $lead->refresh();

    expect($lead->status)->toBe(LeadStatus::Won)
        ->and($lead->customer_id)->not->toBeNull();

    $customer = $lead->customer;

    expect($customer->name)->toBe('Jane Buyer')
        ->and($customer->phone)->toBe('+15550100');

    expect($customer->orders)->toHaveCount(1);
    expect($customer->orders[0]->amount)->toBe('500.00')
        ->and($customer->orders[0]->lead_id)->toBe($lead->id)
        ->and($customer->orders[0]->agent_id)->toBe($this->agent->id)
        ->and($customer->orders[0]->source_id)->toBe($this->source->id);

    // A sale activity is logged on the lead.
    expect($lead->activities->pluck('type'))->toContain(ActivityType::Sale);
});

it('reuses an existing customer for repeat buyers matched by phone', function () {
    $customer = Customer::create([
        'name' => 'Jane Repeat',
        'phone' => '+15550101',
        'email' => 'jane.r@example.com',
    ]);

    $lead = Lead::create([
        'name' => 'Jane (new lead)',
        'phone' => '+15550101',
        'source_id' => $this->source->id,
        'estimated_value' => 300,
        'assigned_agent_id' => $this->agent->id,
    ]);

    $this->service->recordSale($lead, 300);

    expect($lead->refresh()->customer_id)->toBe($customer->id)
        ->and($customer->refresh()->orders)->toHaveCount(1)
        ->and(Customer::count())->toBe(1);
});

it('creates an active subscription with renewal date for recurring products', function () {
    $product = Product::create([
        'name' => 'CRM Pro',
        'type' => ProductType::Subscription,
        'price' => 99,
        'billing_interval' => 'monthly',
        'is_active' => true,
    ]);

    $lead = Lead::create([
        'name' => 'Sub Buyer',
        'phone' => '+15550102',
        'source_id' => $this->source->id,
        'estimated_value' => 99,
        'assigned_agent_id' => $this->agent->id,
    ]);

    $this->service->recordSale($lead, 99, null, $product->id);

    $subscription = $lead->customer->subscriptions->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->interval)->toBe('monthly')
        ->and($subscription->ends_at)->not->toBeNull()
        ->and($subscription->ends_at->isNextMonth())->toBeTrue();
});

it('records the sale without a commission when the lead has no agent', function () {
    $lead = Lead::create([
        'name' => 'Orphan Sale',
        'phone' => '+15550103',
        'source_id' => $this->source->id,
        'estimated_value' => 250,
    ]);

    $commission = $this->service->recordSale($lead, 250);

    expect($commission)->toBeNull()
        ->and($lead->refresh()->status)->toBe(LeadStatus::Won)
        ->and($lead->customer)->not->toBeNull()
        ->and($lead->customer->orders)->toHaveCount(1)
        // No orphan commission rows.
        ->and($lead->commissions()->count())->toBe(0);
});
