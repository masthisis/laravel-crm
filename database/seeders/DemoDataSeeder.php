<?php

namespace Database\Seeders;

use App\Enums\CommissionStatus;
use App\Enums\LeadStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CommissionService;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $agents = User::role(Roles::SALES_STAFF)->get()->all();

        if (count($agents) < 2) {
            $agentA = User::create(['name' => 'Alice Agent', 'email' => 'alice@example.com', 'password' => 'ChangeMe!2024']);
            $agentB = User::create(['name' => 'Bob Agent', 'email' => 'bob@example.com', 'password' => 'ChangeMe!2024']);
            $agentA->assignRole(Roles::SALES_STAFF);
            $agentB->assignRole(Roles::SALES_STAFF);
            $agents = [$agentA, $agentB];
        }

        $products = collect([
            ['name' => 'CRM Starter Pack', 'sku' => 'CRM-START', 'type' => 'one_time', 'price' => 499.00],
            ['name' => 'CRM Professional', 'sku' => 'CRM-PRO', 'type' => 'one_time', 'price' => 1499.00],
            ['name' => 'Premium Support (monthly)', 'sku' => 'SUP-MONTHLY', 'type' => 'subscription', 'price' => 99.00, 'billing_interval' => 'monthly'],
        ])->map(fn ($p) => Product::firstOrCreate(
            ['sku' => $p['sku']],
            $p,
        ));

        $sources = LeadSource::inRandomOrder()->take(4)->get();
        if ($sources->isEmpty()) {
            return;
        }

        $statuses = [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified, LeadStatus::ReEngaged, LeadStatus::Won, LeadStatus::Lost];

        for ($i = 1; $i <= 24; $i++) {
            $source = $sources->random();

            $lead = Lead::create([
                'name' => "Demo Lead {$i}",
                'email' => "lead{$i}@example.com",
                'phone' => sprintf('+1555%07d', $i),
                'source_id' => $source->id,
                'status' => $statuses[$i % count($statuses)],
                'estimated_value' => random_int(200, 5000),
                'assigned_agent_id' => $agents[$i % count($agents)]->id,
                'assigned_at' => now()->subDays(random_int(1, 60)),
            ]);

            // Every third lead "won": record commission via the rules engine.
            if ($i % 3 === 0) {
                app(CommissionService::class)->recordSale($lead, (float) $lead->estimated_value);
            }
        }

        // Customers with purchases & subscriptions.
        $customers = Customer::factory()->count(5)->create();

        foreach ($customers as $index => $customer) {
            $lead = Lead::whereNotNull('assigned_agent_id')->inRandomOrder()->first();
            $agent = $lead?->agent ?? $agents[0];
            $source = $lead?->source ?? $sources->first();

            if ($index % 2 === 0) {
                $lead->update(['customer_id' => $customer->id]);
            }

            $product = $products->random();

            $order = Order::create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'agent_id' => $agent->id,
                'lead_id' => $lead?->id,
                'source_id' => $source?->id,
                'amount' => $product->price,
                'sold_at' => now()->subDays(random_int(1, 120)),
            ]);

            if ($product->type === 'subscription') {
                Subscription::create([
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                    'order_id' => $order->id,
                    'status' => SubscriptionStatus::Active,
                    'amount' => $product->price,
                    'interval' => 'monthly',
                    'started_at' => $order->sold_at,
                ]);
            }
        }

        $this->command?->info('Demo data seeded: 24 leads (8 won with commissions), 5 customers with orders/subscriptions.');
        $this->command?->warn('Note: commissions created here stay '.CommissionStatus::Pending->getLabel().' until an admin approves them.');
    }
}
