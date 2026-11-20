<?php

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Enums\LeadStatus;
use App\Models\Commission;
use App\Models\CommissionRule;
use App\Models\Lead;
use App\Models\LeadSource;

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
     * Record a sale: transition the lead to "won" and create the
     * commission based on the matching rule. Returns null when no
     * rule matches or the commission already exists.
     */
    public function recordSale(Lead $lead, ?float $soldAmount = null, ?int $soldSourceId = null): ?Commission
    {
        if ($lead->commissions()->exists()) {
            return null;
        }

        $amount = $soldAmount ?? (float) ($lead->estimated_value ?? 0);

        $lead->update([
            'status' => LeadStatus::Won,
            'sold_amount' => $amount,
            'sold_source_id' => $soldSourceId ?? $lead->sold_source_id ?? $lead->source_id,
            'sold_at' => now(),
            'lost_at' => null,
            'lost_reason' => null,
        ]);

        $estimate = $this->estimateForLead($lead);

        if ($estimate === null) {
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
