<?php

namespace App\Services\Commission;

use App\Models\CommissionEarning;
use App\Models\CrmDiscount;
use App\Models\CrmInvoice;
use App\Models\LeadAssignment;
use App\Models\LeadContact;
use App\Models\LeadSource;
use App\Support\Jalali;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Commission earning sync (spec 09 §1 + Design A §2.3): read paid
 * invoices, insert-only, idempotent by invoice_id. Recipient = the owner
 * of the used agent discount code (assignment alone earns nothing);
 * ambiguous codes (several owners) get agent NULL + status review.
 * Rate = the buyer's latest assignment's source before payment, else the
 * lead-type rates from the PRD, else the default.
 */
class CommissionSyncService
{
    public const LOCK = 'crm_commission_sync';

    /** PRD rates by lead type (09 §1). */
    public const LEAD_TYPE_RATES = [
        'trial_only' => 6.0,
        'trial_previous' => 4.0,
        'consult' => 2.0,        // app_moshavare
        'churned_20d' => 4.0,
        'renewal' => 3.0,        // app_users_lead
        'unregistered' => 2.0,
        'app_moshavare' => 2.0,
        'app_users_lead' => 3.0,
        'cryptopumpt_link' => 2.0,
        'lead_webinar' => 2.0,
        'unpaid_invoice' => 2.0,
    ];

    public function __construct(
        private readonly int $batch = 500,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('crm.commission.start_at');
    }

    /**
     * One sync pass; a cache lock lets only one run at a time and
     * concurrent runs skip (Design A §2.3).
     *
     * @return array{processed: int, skipped: bool}
     */
    public function sync(): array
    {
        if (! $this->enabled()) {
            return ['processed' => 0, 'skipped' => true];
        }

        return Cache::lock(self::LOCK, 300)->block(2, function () {
            $startAt = Carbon::parse((string) config('crm.commission.start_at'));

            $invoices = CrmInvoice::query()
                ->where('status', CrmInvoice::STATUS_PAID)
                ->where('invoice_date', '>=', $startAt)
                ->whereNotNull('discount')
                ->where('discount', '!=', '')
                ->where('discount', '!=', '0')
                ->whereNotIn('id', CommissionEarning::query()->select('invoice_id'))
                ->orderBy('id')
                ->limit($this->batch)
                ->get();

            $processed = 0;
            foreach ($invoices as $invoice) {
                if ($this->recordEarning($invoice, $startAt)) {
                    $processed++;
                }
            }

            return ['processed' => $processed, 'skipped' => false];
        });
    }

    /** Record one earning. Returns false when no agent code owns it. */
    public function recordEarning(CrmInvoice $invoice, Carbon $startAt): bool
    {
        $code = trim((string) $invoice->discount);

        // Owners = the distinct operators owning rows with this code (case-insensitive).
        $owners = CrmDiscount::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower($code)])
            ->where('operatorid', '>', 0)
            ->pluck('operatorid')
            ->unique();

        if ($owners->isEmpty()) {
            return false; // no agent code → earns nothing (09 §1)
        }

        $paidAt = $invoice->paid_at ?? $invoice->invoice_date ?? now();
        $buyerId = $invoice->customer_id;
        $buyerPhone = $invoice->phone;

        // Rate: the buyer's latest assignment before payment, whoever held it.
        $assignment = null;
        if ($buyerId !== null || $buyerPhone !== null) {
            $contactQuery = LeadContact::query();
            if ($buyerPhone !== null) {
                $contactQuery->where('phone', $buyerPhone);
            } elseif ($buyerId !== null) {
                $contactQuery->where('customer_id', $buyerId);
            }

            $contactId = $contactQuery->value('id');
            if ($contactId !== null) {
                $assignment = LeadAssignment::query()
                    ->where('contact_id', $contactId)
                    ->where('created_at', '<=', $paidAt)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();
            }
        }

        [$rate, $sourceId, $sourceName] = $assignment !== null && $assignment->source_id !== null
            ? $this->rateFromSource($assignment->source_id)
            : $this->rateForLeadType($assignment?->crm_lead_type);

        $paidAmount = (int) $invoice->total;
        $amount = (int) round($paidAmount * $rate / 100);

        CommissionEarning::create([
            'invoice_id' => $invoice->id,
            // Ambiguous code → review with agent NULL (09 §2.3 step 2).
            'agent_id' => $owners->count() === 1 ? $owners->first() : null,
            'discount_code' => $code,
            'customer_id' => $buyerId,
            'assignment_id' => $assignment?->id,
            'source_id' => $sourceId,
            'source_name' => $sourceName,
            'rate' => $rate,
            'paid_amount' => $paidAmount,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'month' => Jalali::monthKey($paidAt),
            'status' => $owners->count() === 1 ? 'ok' : 'review',
        ]);

        return true;
    }

    /** @return array{0: float, 1: int|null, 2: string|null} */
    private function rateFromSource(int $sourceId): array
    {
        $source = LeadSource::query()->find($sourceId);

        return [
            (float) ($source->commission_percent ?? config('crm.commission.default_percent', 2)),
            $sourceId,
            $source?->name,
        ];
    }

    /** PRD rate by lead type, else the default (09 §1). */
    /** @return array{0: float, 1: int|null, 2: string|null} */
    private function rateForLeadType(?string $leadType): array
    {
        $rate = self::LEAD_TYPE_RATES[$leadType] ?? (float) config('crm.commission.default_percent', 2);

        return [$rate, null, null];
    }
}
