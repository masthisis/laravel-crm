<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\CommissionStatus;
use App\Enums\LeadStatus;
use App\Enums\ProductType;
use App\Enums\SubscriptionStatus;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Commission rules engine.
 *
 * A rule matches a lead when every source it defines equals the lead's
 * source (original / re-engaged / sold). A NULL source on the rule acts
 * as a wildcard. The most SPECIFIC matching rule wins; ties are broken
 * by the highest priority, then by the newest rule.
 */
class CommissionService
{
    public function matchingRule(Lead $lead): ?CommissionRule
    {
        return CommissionRule::query()
            ->active()
            ->orderByDesc('priority')
            ->get()
            ->filter(fn (CommissionRule $rule) => $this->matches($rule, $lead))
            ->sortByDesc(fn (CommissionRule $rule) => $this->specificity($rule, $lead))
            ->first();
    }

    /**
     * Estimate the commission for a lead without creating any record.
     *
     * @return array{rule: CommissionRule, rate: float, amount: float}|null
     */
    public function estimateForLead(Lead $lead): ?array
    {
        $rule = $this->matchingRule($lead);

        if ($rule === null) {
            return null;
        }

        $base = (float) ($lead->sold_amount ?? $lead->estimated_value ?? 0);

        return [
            'rule' => $rule,
            'rate' => (float) $rule->rate,
            'amount' => $this->calculate($base, (float) $rule->rate),
        ];
    }

    /**
     * Record a sale: transition the lead to "won", find-or-create the
     * customer, create the order (+ subscription for recurring products),
     * log the sale activity and create the commission based on the
     * matching rule.
     *
     * Returns null when a commission already exists, no agent is assigned
     * (sale is still recorded, but no commission can be attributed) or no
     * rule matches.
     */
    public function recordSale(Lead $lead, ?float $soldAmount = null, ?int $soldSourceId = null, ?int $productId = null, int $quantity = 1): ?Commission
    {
        if ($lead->commissions()->exists()) {
            return null;
        }

        $amount = $soldAmount ?? (float) ($lead->estimated_value ?? 0);

        return DB::transaction(function () use ($lead, $amount, $soldSourceId, $productId, $quantity): ?Commission {
            $lead->update([
                'status' => LeadStatus::Won,
                'sold_amount' => $amount,
                'sold_source_id' => $soldSourceId ?? $lead->sold_source_id ?? $lead->source_id,
                'sold_at' => now(),
                'lost_at' => null,
                'lost_reason' => null,
            ]);

            $product = $productId !== null ? Product::find($productId) : null;

            $customer = $this->resolveCustomerForSale($lead);
            $lead->update(['customer_id' => $customer->id]);

            $order = $customer->orders()->create([
                'product_id' => $product?->id,
                'agent_id' => $lead->assigned_agent_id,
                'lead_id' => $lead->id,
                'source_id' => $lead->sold_source_id ?? $lead->source_id,
                'amount' => $amount,
                'sold_at' => now(),
                'notes' => $product !== null
                    ? ($quantity > 1 ? "Sold {$quantity} × {$product->name}" : "Sold {$product->name}")
                    : 'Direct sale',
            ]);

            if ($product !== null && $product->type->value === ProductType::Subscription->value) {
                $order->subscriptions()->create([
                    'customer_id' => $customer->id,
                    'product_id' => $product->id,
                    'status' => SubscriptionStatus::Active,
                    'amount' => (float) $product->price,
                    'interval' => $product->billing_interval ?: 'monthly',
                    'started_at' => now(),
                    'ends_at' => $this->subscriptionRenewalDate($product->billing_interval),
                ]);
            }

            $lead->activities()->create([
                'agent_id' => $lead->assigned_agent_id,
                'type' => ActivityType::Sale,
                'notes' => $product !== null
                    ? "Sale recorded — {$order->amount} ".config('crm.currency', 'USD')." ({$product->name})"
                    : 'Sale recorded — '.$order->amount.' '.config('crm.currency', 'USD'),
                'occurred_at' => now(),
            ]);

            $estimate = $this->estimateForLead($lead);

            if ($estimate === null || $lead->assigned_agent_id === null) {
                return null;
            }

            return Commission::create([
                'lead_id' => $lead->id,
                'agent_id' => $lead->assigned_agent_id,
                'commission_rule_id' => $estimate['rule']->id,
                'original_source_id' => $lead->source_id,
                're_engaged_source_id' => $lead->re_engaged_source_id,
                'sold_source_id' => $lead->sold_source_id,
                'base_amount' => $amount,
                'rate' => $estimate['rate'],
                'amount' => $estimate['amount'],
                'status' => CommissionStatus::Pending,
                'sold_at' => now(),
            ]);
        });
    }

    /**
     * Find-or-create the customer behind a won lead: match by phone,
     * then email, then fall back to creating a fresh record.
     */
    protected function resolveCustomerForSale(Lead $lead): Customer
    {
        if (filled($lead->phone)) {
            $customer = Customer::query()->where('phone', $lead->phone)->first();

            if ($customer !== null) {
                return $customer;
            }
        }

        if (filled($lead->email)) {
            $customer = Customer::query()->where('email', $lead->email)->first();

            if ($customer !== null) {
                return $customer;
            }
        }

        return Customer::create([
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
        ]);
    }

    protected function subscriptionRenewalDate(?string $interval): CarbonInterface
    {
        return match ($interval) {
            'weekly' => now()->addWeek(),
            'quarterly' => now()->addQuarter(),
            'yearly' => now()->addYear(),
            default => now()->addMonth(),
        };
    }

    public function calculate(float $base, float $rate): float
    {
        return round($base * $rate / 100, (int) config('crm.commission.rounding', 2));
    }

    protected function matches(CommissionRule $rule, Lead $lead): bool
    {
        if ($rule->original_source_id !== null && (int) $rule->original_source_id !== (int) $lead->source_id) {
            return false;
        }

        if ($rule->re_engaged_source_id !== null && (int) $rule->re_engaged_source_id !== (int) $lead->re_engaged_source_id) {
            return false;
        }

        if ($rule->sold_source_id !== null && (int) $rule->sold_source_id !== (int) $lead->sold_source_id) {
            return false;
        }

        return true;
    }

    protected function specificity(CommissionRule $rule, Lead $lead): int
    {
        return
            ($rule->original_source_id !== null ? 4 : 0)
            + ($rule->re_engaged_source_id !== null && $lead->re_engaged_source_id !== null ? 2 : 0)
            + ($rule->sold_source_id !== null && $lead->sold_source_id !== null ? 2 : 0);
    }

    /**
     * Resolve (or create) a source by name — used by importers and syncs.
     */
    public static function resolveSource(string $name, string $type = 'manual'): LeadSource
    {
        return LeadSource::firstOrCreate(
            ['slug' => str($name)->slug()->toString()],
            [
                'name' => trim($name),
                'type' => $type,
                'is_active' => true,
            ]
        );
    }
}
