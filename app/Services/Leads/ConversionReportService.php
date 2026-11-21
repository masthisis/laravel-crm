<?php

namespace App\Services\Leads;

use App\Models\LeadAssignment;
use App\Models\LeadEvent;
use App\Models\Order;
use App\Models\Subscription;
use App\Support\Jalali;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conversion report «گزارش نرخ تبدیل» (spec 05 §10).
 *
 * Built from lead_assignments, never from the current owner only.
 * Every count is DISTINCT contacts, so re-referrals and snoozes never
 * add leads. Converted = a purchase at or after the referral and inside
 * the purchase range. History view credits every agent the lead was
 * referred to before the purchase; owner view credits only the agent
 * holding the lead at purchase time — unless that agent rejected the
 * referral first.
 */
class ConversionReportService
{
    /**
     * @param  array{from: Carbon, to: Carbon}  $referralRange
     * @param  array{from: Carbon, to: Carbon}  $purchaseRange
     * @param  array{kind: 'order'|'subscription', product_ids?: list<int>, plan?: string}  $purchaseDefinition
     * @param  'history'|'owner'  $view
     * @param  'weekly'|'monthly'|'agent'  $grouping
     * @return list<array<string, mixed>>
     */
    public function report(
        array $referralRange,
        array $purchaseRange,
        array $purchaseDefinition,
        string $view = 'history',
        string $grouping = 'agent',
    ): array {
        $assignments = LeadAssignment::query()
            ->whereBetween('created_at', [$referralRange['from'], $referralRange['to']])
            ->with(['contact'])
            ->orderBy('created_at')
            ->get();

        // Purchases as [contact_id => list of purchase datetimes].
        $purchases = $this->purchasesByContact($purchaseRange, $purchaseDefinition);

        // Rejections: contact + operator + time — ends responsibility.
        $rejects = collect();
        $rejectRows = LeadEvent::query()->where('type', LeadEvent::TYPE_REJECT)->whereNotNull('ref_id')->get();
        foreach ($rejectRows as $reject) {
            $refOperator = DB::table('lead_assignments')
                ->where('crm_referral_id', $reject->ref_id)
                ->value('operator_id');
            $rejects->push(['contact_id' => $reject->contact_id, 'operator_id' => $refOperator, 'at' => $reject->created_at]);
        }

        // Bucket per grouping.
        $buckets = [];

        foreach ($assignments as $assignment) {
            $contactId = $assignment->contact_id;
            $referredAt = $assignment->created_at;
            $bucketKey = $grouping === 'agent'
                ? 'op-'.$assignment->operator_id
                : $this->bucketKey($referredAt, $grouping);

            $bucket = $buckets[$bucketKey] ??= [
                'key' => $bucketKey,
                'label' => $grouping === 'agent' ? ($assignment->operator->name ?? 'کارشناس #'.$assignment->operator_id) : $this->bucketLabel($referredAt, $grouping),
                'operator_id' => $assignment->operator_id,
                'contacts' => collect(), // DISTINCT contact ids per bucket
                'converted' => collect(),
                'buyers' => collect(),
                'subscriptions' => 0,
            ];

            $bucket['contacts']->push($contactId);

            // Purchase matching for this assignment.
            $contactPurchases = $purchases->get($contactId, collect());
            $relevant = $contactPurchases->filter(
                static fn (Carbon $at) => $at->gte($referredAt),
            );

            if ($relevant->isEmpty()) {
                continue;
            }

            if ($view === 'history') {
                // Every agent the lead was referred to before it.
                $bucket['converted']->push($contactId);
                $bucket['buyers']->push($contactId);
            } else {
                // Owner view: the latest assignment before the purchase,
                // unless that agent rejected the referral first.
                foreach ($relevant as $purchaseAt) {
                    $owner = $this->ownerAt($contactId, $purchaseAt, $rejects);
                    if ($owner === null || $owner === $assignment->operator_id) {
                        if ($owner === $assignment->operator_id) {
                            $bucket['converted']->push($contactId);
                            $bucket['buyers']->push($contactId);
                        }
                    }
                }
            }
        }

        // Subscription count per bucket (purchase definition = subscription plan).
        if ($purchaseDefinition['kind'] === 'subscription') {
            foreach ($buckets as &$bucket) {
                $bucket['subscriptions'] = Subscription::query()
                    ->whereIn('customer_id', $bucket['contacts']->unique()->values())
                    ->whereBetween('started_at', [$purchaseRange['from'], $purchaseRange['to']])
                    ->when($purchaseDefinition['plan'] ?? null, static fn ($q, $plan) => $q->where('interval', $plan))
                    ->count();
            }
            unset($bucket);
        }

        return collect($buckets)
            ->map(static function (array $bucket) {
                $contacts = $bucket['contacts']->unique()->count();
                $converted = $bucket['converted']->unique()->count();
                $buyers = $bucket['buyers']->unique()->count();

                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'referred' => $contacts,
                    'converted' => $converted,
                    'buyers' => $buyers,
                    'rate' => $contacts > 0 ? round($converted / $contacts * 100, 1) : 0.0,
                    'subscriptions' => $bucket['subscriptions'],
                ];
            })
            ->sortByDesc('referred')
            ->values()
            ->all();
    }

    /**
     * Purchases grouped by contact id.
     *
     * @param  array{from: Carbon, to: Carbon}  $range
     * @param  array{kind: 'order'|'subscription', product_ids?: list<int>, plan?: string}  $definition
     * @return Collection<int, Collection<int, Carbon>>
     */
    private function purchasesByContact(array $range, array $definition): Collection
    {
        $map = collect();

        if ($definition['kind'] === 'subscription') {
            $rows = Subscription::query()
                ->whereBetween('started_at', [$range['from'], $range['to']])
                ->when($definition['plan'] ?? null, static fn ($q, $plan) => $q->where('interval', $plan))
                ->get(['customer_id', 'started_at']);
        } else {
            // A paid order. The generic orders table stores sold_at.
            $rows = Order::query()
                ->whereBetween('sold_at', [$range['from'], $range['to']])
                ->get(['customer_id', 'sold_at', 'product_id']);
        }

        foreach ($rows as $row) {
            $at = Carbon::parse($definition['kind'] === 'subscription' ? $row->started_at : $row->sold_at);
            $productId = $row->product_id ?? null;

            if ($definition['kind'] === 'order' && ! empty($definition['product_ids'])) {
                if ($productId === null || ! in_array((int) $productId, $definition['product_ids'], true)) {
                    continue;
                }
            }

            if (! $map->has($row->customer_id)) {
                $map->put($row->customer_id, collect());
            }
            $map->get($row->customer_id)->push($at);
        }

        return $map;
    }

    /**
     * The agent holding the lead at purchase time: the latest assignment
     * before it, unless that agent rejected the referral first.
     */
    private function ownerAt(int $contactId, Carbon $purchaseAt, $rejects): ?int
    {
        $latest = LeadAssignment::query()
            ->where('contact_id', $contactId)
            ->where('created_at', '<=', $purchaseAt)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        $rejected = $rejects->first(
            static fn (array $r) => $r['contact_id'] === $contactId
                && $r['operator_id'] === $latest->operator_id
                && $r['at'] !== null
                && Carbon::parse($r['at'])->lte($purchaseAt),
        );

        return $rejected !== null ? null : $latest->operator_id;
    }

    private function bucketKey(Carbon $at, string $grouping): string
    {
        if ($grouping === 'monthly') {
            return Jalali::monthKey($at);
        }

        // ISO-style week starting Saturday (Iranian week).
        return 'w-'.$at->format('o-W');
    }

    private function bucketLabel(Carbon $at, string $grouping): string
    {
        if ($grouping === 'monthly') {
            return Jalali::monthKey($at);
        }

        return Jalali::format($at->startOfWeek(), 'Y/m/d');
    }
}
