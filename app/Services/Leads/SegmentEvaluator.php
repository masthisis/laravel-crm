<?php

namespace App\Services\Leads;

use App\Models\CrmCallLog;
use App\Models\CrmInstallment;
use App\Models\CrmInvoice;
use App\Models\Customer;
use App\Models\LeadActivity;
use App\Models\LeadAssignment;
use App\Models\LeadContact;
use App\Models\LeadContactSource;
use App\Models\LeadEntry;
use App\Models\LeadSource;
use App\Models\Product;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Segment rule tree (spec 05 §4): a rule set over accounts giving the
 * matching customer ids. Max depth 3, max 20 rules, `negate` = must not
 * hold. Results snapshot into lead_segment_caches for 600 s.
 */
class SegmentEvaluator
{
    public const MAX_DEPTH = 3;

    public const MAX_RULES = 20;

    public const CACHE_TTL_SECONDS = 600;

    /** @param array{match?: string, rules?: list<array<string, mixed>>} $tree */
    public function evaluate(array $tree): array
    {
        $this->assertShape($tree, 1, 0);

        return array_values(array_unique($this->evalNode($tree)));
    }

    /** Live preview count (no cache). */
    public function count(array $tree): int
    {
        return count($this->evaluate($tree));
    }

    /**
     * Cached snapshot: rebuild under a DB lock while readers keep the old
     * snapshot (05 §4).
     *
     * @return list<int>
     */
    public function cached(string $segmentSlug, array $tree, bool $force = false): array
    {
        $latest = DB::table('lead_segment_caches')
            ->where('segment_slug', $segmentSlug)
            ->max('built_at');

        $fresh = $latest !== null && Carbon::parse($latest)->gt(now()->subSeconds(self::CACHE_TTL_SECONDS));

        if (! $fresh || $force) {
            // Rebuild under a DB lock while other readers keep the old snapshot.
            $ids = $this->evaluate($tree);
            $builtAt = now();

            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = array_map(static fn ($id) => [
                    'segment_slug' => $segmentSlug,
                    'customer_id' => $id,
                    'built_at' => $builtAt,
                ], $chunk);
                DB::table('lead_segment_caches')->insertOrIgnore($rows);
            }

            return $ids;
        }

        return DB::table('lead_segment_caches')
            ->where('segment_slug', $segmentSlug)
            ->where('built_at', $latest)
            ->pluck('customer_id')
            ->all();
    }

    /** @param array<string, mixed> $node */
    private function assertShape(array $node, int $depth, int $count): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Segment tree exceeds depth 3');
        }

        $rules = $node['rules'] ?? [];
        $count += count($rules);
        if ($count > self::MAX_RULES) {
            throw new RuntimeException('Segment tree exceeds 20 rules');
        }

        foreach ($rules as $rule) {
            if (isset($rule['rules']) && is_array($rule['rules'])) {
                $this->assertShape($rule, $depth + 1, $count);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<int>
     */
    private function evalNode(array $node): array
    {
        $match = $node['match'] ?? 'all';
        $results = [];

        foreach ($node['rules'] ?? [] as $rule) {
            if (isset($rule['rules']) && is_array($rule['rules'])) {
                $results[] = $this->evalNode($rule);

                continue;
            }

            $results[] = $this->evalCriterion($rule);
        }

        if ($results === []) {
            return [];
        }

        if ($match === 'any') {
            return array_values(array_unique(array_merge(...$results)));
        }

        // "all": intersect, starting from the first rule.
        $intersection = $results[0];
        foreach (array_slice($results, 1) as $set) {
            $intersection = array_values(array_intersect($intersection, $set));
        }

        return $intersection;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<int>
     */
    private function evalCriterion(array $rule): array
    {
        $criterion = (string) ($rule['criterion'] ?? '');
        $params = is_array($rule['params'] ?? null) ? $rule['params'] : [];
        $negate = (bool) ($rule['negate'] ?? false);

        $ids = $this->criterion($criterion, $params);

        if ($negate) {
            $all = Customer::query()->pluck('id')->all();

            return array_values(array_diff($all, $ids));
        }

        return $ids;
    }

    /**
     * Criterion registry (05 §4.1). App-activity criteria read the
     * legacy connection when enabled and return [] otherwise.
     *
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function criterion(string $key, array $p): array
    {
        return match ($key) {
            'order' => $this->orderCriterion($p),
            'subscription' => $this->subscriptionCriterion($p),
            'purchase_stats' => $this->purchaseStatsCriterion($p),
            'installment' => $this->installmentCriterion($p),
            'call' => $this->callCriterion($p),
            'last_login' => Customer::query()->whereBetween('last_login_at', [now()->subDays((int) ($p['within_days'] ?? 30)), now()])->pluck('id')->all(),
            'signup' => Customer::query()->whereBetween('created_at', [now()->subDays((int) ($p['last_days'] ?? 30)), now()])->pluck('id')->all(),
            'user_add_from' => Customer::query()->whereIn('add_from', $p['values'] ?? [])->pluck('id')->all(),
            'user_status' => Customer::query()->whereIn('account_status', $p['values'] ?? [])->pluck('id')->all(),
            'utm_link' => $this->utmLinkCriterion($p),
            'assigned' => $this->assignedCriterion($p),
            'in_source' => $this->inSourceCriterion($p),
            'lead_reach' => $this->leadReachCriterion($p),
            'webinar' => $this->webinarCriterion($p),
            'ticket' => $this->ticketCriterion($p),
            // App-activity criteria — legacy reads only.
            'declared_assets', 'risk_test', 'story_view', 'signal_check', 'watchlist' => $this->legacyCriterion($key, $p),
            default => throw new RuntimeException("Unknown segment criterion: {$key}"),
        };
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function orderCriterion(array $p): array
    {
        $status = $p['status'] ?? 'any';

        $query = CrmInvoice::query();

        if ($status === 'paid') {
            // Paid for segments = 2/3/4 (01 §2.5).
            $query->whereIn('status', [CrmInvoice::STATUS_PAID, CrmInvoice::STATUS_PREPARING, CrmInvoice::STATUS_SHIPPED])
                ->where(static function ($q) {
                    // Status 3 on pay_type 8/9 is a failed checkout = unpaid (11 #25).
                    $q->where('status', '!=', CrmInvoice::STATUS_PREPARING)
                        ->orWhereNotIn('pay_type', CrmInvoice::FAILED_CHECKOUT_PAY_TYPES);
                });
        } elseif ($status === 'unpaid') {
            $query->where(static function ($q) {
                $q->where('status', CrmInvoice::STATUS_UNPAID)
                    ->orWhere(static function ($q2) {
                        $q2->where('status', CrmInvoice::STATUS_PREPARING)->whereIn('pay_type', CrmInvoice::FAILED_CHECKOUT_PAY_TYPES);
                    });
            });
        } elseif ($status === 'any') {
            // "any" excludes rejected/deleted.
            $query->whereNotIn('status', [CrmInvoice::STATUS_REJECTED, CrmInvoice::STATUS_DELETED]);
        }

        if (! empty($p['product_group'])) {
            // Product-group keys map through the product SKU.
            $productIds = Product::query()->whereIn('sku', $p['product_group'])->pluck('id');
            $query->whereHas('lines', static function ($q) use ($productIds) {
                $q->whereIn('product_id', $productIds);
            });
        } elseif (! empty($p['products'])) {
            $query->whereHas('lines', static function ($q) use ($p) {
                $q->whereIn('product_id', $p['products']);
            });
        }

        if (! empty($p['discount'])) {
            $query->where('discount', $p['discount']);
        }

        if (! empty($p['pay_types'])) {
            $query->whereIn('pay_type', $p['pay_types']);
        }

        if (! empty($p['last_days'])) {
            $query->whereBetween('invoice_date', [now()->subDays((int) $p['last_days']), now()]);
        } elseif (! empty($p['from']) || ! empty($p['to'])) {
            $query->whereBetween('invoice_date', [
                ! empty($p['from']) ? Carbon::parse($p['from']) : Carbon::create(2000),
                ! empty($p['to']) ? Carbon::parse($p['to']) : now(),
            ]);
        }

        $ids = $query->pluck('customer_id')->filter()->all();

        // exclude_paid_in_period: no paid invoice in the same dates (05 §4.1).
        if (! empty($p['exclude_paid_in_period'])) {
            $paidQuery = CrmInvoice::query()
                ->whereIn('status', [CrmInvoice::STATUS_PAID, CrmInvoice::STATUS_PREPARING, CrmInvoice::STATUS_SHIPPED])
                ->where(static function ($q) {
                    $q->where('status', '!=', CrmInvoice::STATUS_PREPARING)
                        ->orWhereNotIn('pay_type', CrmInvoice::FAILED_CHECKOUT_PAY_TYPES);
                });

            if (! empty($p['from']) || ! empty($p['to'])) {
                $paidQuery->whereBetween('invoice_date', [
                    ! empty($p['from']) ? Carbon::parse($p['from']) : Carbon::create(2000),
                    ! empty($p['to']) ? Carbon::parse($p['to']) : now(),
                ]);
            } elseif (! empty($p['last_days'])) {
                $paidQuery->whereBetween('invoice_date', [now()->subDays((int) $p['last_days']), now()]);
            }

            return array_values(array_diff($ids, $paidQuery->pluck('customer_id')->filter()->all()));
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function subscriptionCriterion(array $p): array
    {
        $query = Subscription::query();

        $endsFrom = (int) ($p['ends_from_days'] ?? 0);
        $endsTo = (int) ($p['ends_to_days'] ?? 3650);

        if (isset($p['ends_from_days']) || isset($p['ends_to_days'])) {
            $query->whereBetween('ends_at', [now()->addDays($endsFrom)->startOfDay(), now()->addDays($endsTo)->endOfDay()]);
        }

        if (! empty($p['statuses'])) {
            $query->whereIn('status', array_map('strval', $p['statuses']));
        }

        if (! empty($p['plans'])) {
            $query->whereIn('interval', $p['plans']);
        }

        if (! empty($p['types'])) {
            $query->whereIn('type', $p['types']);
        }

        return $query->pluck('customer_id')->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function purchaseStatsCriterion(array $p): array
    {
        $rows = CrmInvoice::query()
            ->whereIn('status', [CrmInvoice::STATUS_PAID, CrmInvoice::STATUS_PREPARING, CrmInvoice::STATUS_SHIPPED])
            ->selectRaw('customer_id, COUNT(*) as orders, COALESCE(SUM(total), 0) as total')
            ->groupBy('customer_id')
            ->get();

        return $rows
            ->filter(static function (object $row) use ($p) {
                $minOrders = $p['min_orders'] ?? null;
                $maxOrders = $p['max_orders'] ?? null;
                $minTotal = $p['min_total'] ?? null;
                $maxTotal = $p['max_total'] ?? null;

                if ($minOrders !== null && (int) $row->getAttribute('orders') < $minOrders) {
                    return false;
                }
                if ($maxOrders !== null && (int) $row->getAttribute('orders') > $maxOrders) {
                    return false;
                }
                if ($minTotal !== null && $row->total < $minTotal) {
                    return false;
                }
                if ($maxTotal !== null && $row->total > $maxTotal) {
                    return false;
                }

                return true;
            })
            ->pluck('customer_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function installmentCriterion(array $p): array
    {
        $state = $p['state'] ?? 'unpaid';

        $query = CrmInstallment::query();
        $query = match ($state) {
            'overdue' => $query->overdue(),
            'paid' => $query->where('status', CrmInstallment::STATUS_PAID),
            default => $query->where('status', CrmInstallment::STATUS_UNPAID),
        };

        return $query->pluck('customer_id')->all();
    }

    /**
     * Call criterion matched by phone (05 §4.1): direction, outcome,
     * min_seconds implies answered.
     *
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function callCriterion(array $p): array
    {
        $query = CrmCallLog::query();

        if (($p['direction'] ?? 'all') !== 'all') {
            $query->where('type', $p['direction']);
        }

        $outcome = $p['outcome'] ?? 'all';
        $minSeconds = $p['min_seconds'] ?? null;

        if ($outcome === 'answered' || $minSeconds !== null) {
            $query->answered();
        } elseif ($outcome === 'unanswered') {
            $query->where(static function ($q) {
                $q->whereNull('accepted_call')->orWhere('accepted_call', '');
            });
        }

        if ($minSeconds !== null) {
            $query->where('call_time', '>=', (int) $minSeconds);
        }

        if (! empty($p['within_days'])) {
            $query->where('called_at', '>=', now()->subDays((int) $p['within_days']));
        }

        $phones = $query->pluck('phone')->filter()->unique()->all();

        return LeadContact::query()->whereIn('phone', $phones)->pluck('customer_id')->filter()->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function utmLinkCriterion(array $p): array
    {
        if (! Schema::hasTable('utm_records')) {
            return [];
        }

        $query = DB::table('utm_records')->whereNotNull('identification_type');

        if (! empty($p['links'])) {
            $query->whereIn('link_id', $p['links']);
        }

        if (! empty($p['types'])) {
            $query->whereIn('identification_type', $p['types']);
        }

        $customers = [];
        foreach ($query->get() as $record) {
            $customerId = $record->user_id;
            if ($customerId === null && $record->phone !== null) {
                $customerId = Customer::findByCanonicalPhone($record->phone)?->id;
            }
            if ($customerId !== null) {
                $customers[] = (int) $customerId;
            }
        }

        return array_values(array_unique($customers));
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function assignedCriterion(array $p): array
    {
        $query = LeadAssignment::query();

        if (! empty($p['crm_lead_type'])) {
            $query->where('crm_lead_type', $p['crm_lead_type']);
        }
        if (! empty($p['operator'])) {
            $query->where('operator_id', $p['operator']);
        }
        if (! empty($p['within_days'])) {
            $query->where('created_at', '>=', now()->subDays((int) $p['within_days']));
        }

        return $query->pluck('contact_id')->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function inSourceCriterion(array $p): array
    {
        $query = LeadEntry::query()->whereIn('source_id', LeadSource::query()->whereIn('slug', $p['sources'] ?? [])->pluck('id'));

        if (! empty($p['within_days'])) {
            $query->where('captured_at', '>=', now()->subDays((int) $p['within_days']));
        }

        return $query->pluck('contact_id')->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function leadReachCriterion(array $p): array
    {
        $min = (int) ($p['min_sources'] ?? 2);
        $query = LeadContactSource::query();

        if (! empty($p['within_days'])) {
            $query->where('last_at', '>=', now()->subDays((int) $p['within_days']));
        }

        return $query
            ->groupBy('contact_id')
            ->havingRaw('COUNT(DISTINCT source_id) >= ?', [$min])
            ->select('contact_id')
            ->pluck('contact_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function webinarCriterion(array $p): array
    {
        if (! Schema::hasTable('lead_entries')) {
            return [];
        }

        $source = LeadSource::query()->where('slug', 'webinar')->first();
        if ($source === null) {
            return [];
        }

        $query = LeadEntry::query()->where('source_id', $source->id);

        if (! empty($p['within_days'])) {
            $query->where('captured_at', '>=', now()->subDays((int) $p['within_days']));
        }

        if (isset($p['new_user'])) {
            $query->where(static function ($q) use ($p) {
                $q->where('payload->new_user', (int) $p['new_user']);
            });
        }

        return $query->pluck('contact_id')->all();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function ticketCriterion(array $p): array
    {
        $query = LeadActivity::query();

        if (! empty($p['within_days'])) {
            $query->where('occurred_at', '>=', now()->subDays((int) $p['within_days']));
        }

        return $query->whereNotNull('customer_id')->pluck('customer_id')->all();
    }

    /**
     * App-activity criteria read the legacy DB (05 §4.1) — empty when the
     * connection is disabled. The adapters map to the spec's tables.
     *
     * @param  array<string, mixed>  $p
     * @return list<int>
     */
    private function legacyCriterion(string $key, array $p): array
    {
        if (! (bool) config('crm.legacy.enabled')) {
            return [];
        }

        $map = [
            'declared_assets' => ['rayan_app_user_financials', 'user_id'],
            'risk_test' => ['rayan_app_users_test_answers', 'user_id'],
            'story_view' => ['rayan_apps_story_view', 'user_id'],
            'signal_check' => ['rayan_app_check_signals', 'user_id'],
            'watchlist' => ['rayan_watchlist_crypto', 'user_id'],
        ];

        [$table, $userColumn] = $map[$key];
        $connection = config('crm.legacy.connection', 'legacy');

        $query = DB::connection($connection)->table($table);

        if (! empty($p['within_days'])) {
            $query->where('created_at', '>=', now()->subDays((int) $p['within_days'])->toDateTimeString());
        }

        $legacyIds = $query->pluck($userColumn)->unique()->all();
        if ($legacyIds === []) {
            return [];
        }

        return Customer::query()->whereIn('id', $legacyIds)->pluck('id')->all();
    }
}
