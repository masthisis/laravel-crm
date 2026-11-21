<?php

namespace App\Services\Intake;

use App\Models\CrmCallLog;
use App\Models\CrmEventReceipt;
use App\Models\CrmInvoice;
use App\Models\CrmInvoiceLine;
use App\Models\Customer;
use App\Models\LeadContact;
use App\Models\LeadEntry;
use App\Models\LeadSource;
use App\Services\Leads\CaptureResult;
use App\Services\Leads\LeadCaptureService;
use App\Support\Persian;
use App\Support\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Event intake (spec 03): every event type maps onto the LeadService
 * intake or a CRM mirror table. The (source, origin_id) idempotency key
 * makes event + poll replays no-ops; crm_event_receipts dedupes by
 * event_id itself.
 */
class EventIntakeService
{
    public const TYPE_LEAD_CAPTURED = 'lead.captured';

    public const TYPE_LEAD_VERIFIED = 'lead.verified';

    public const TYPE_LEAD_PAID = 'lead.paid';

    public const TYPE_ACCOUNT_CREATED = 'account.created';

    public const TYPE_ACCOUNT_VERIFIED = 'account.verified';

    public const TYPE_INVOICE_CREATED = 'invoice.created';

    public const TYPE_INVOICE_PAID = 'invoice.paid';

    public const TYPE_CALL_LOGGED = 'call.logged';

    public const TYPE_CALL_COMPLETED = 'call.completed';

    public const TYPE_UTM_IDENTIFIED = 'utm.identified';

    public const TYPE_ATTRIBUTION_RECORDED = 'attribution.recorded';

    public const SUPPORTED = [
        self::TYPE_LEAD_CAPTURED,
        self::TYPE_LEAD_VERIFIED,
        self::TYPE_LEAD_PAID,
        self::TYPE_ACCOUNT_CREATED,
        self::TYPE_ACCOUNT_VERIFIED,
        self::TYPE_INVOICE_CREATED,
        self::TYPE_INVOICE_PAID,
        self::TYPE_CALL_LOGGED,
        self::TYPE_CALL_COMPLETED,
        self::TYPE_UTM_IDENTIFIED,
        self::TYPE_ATTRIBUTION_RECORDED,
    ];

    public function __construct(
        private readonly LeadCaptureService $capture,
    ) {}

    /**
     * Process one event envelope.
     *
     * @param  array<string, mixed>  $event
     * @return array{status: int, result: string}
     */
    public function accept(array $event): array
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        $idempotencyKey = isset($event['idempotency_key']) ? (string) $event['idempotency_key'] : null;

        if ($eventId === '' || ! in_array($type, self::SUPPORTED, true)) {
            return ['status' => 422, 'result' => 'invalid_event'];
        }

        // Replay-safe: one receipt per event_id.
        try {
            CrmEventReceipt::create([
                'event_id' => $eventId,
                'type' => $type,
                'idempotency_key' => $idempotencyKey,
                'result' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['status' => 200, 'result' => 'duplicate_event'];
        }

        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $result = match ($type) {
            self::TYPE_LEAD_CAPTURED => $this->handleLeadCaptured($event, $data),
            self::TYPE_LEAD_VERIFIED => $this->handleLeadVerified($event, $data, 'verified', 1),
            self::TYPE_LEAD_PAID => $this->handleLeadVerified($event, $data, 'consult_status', 2),
            self::TYPE_ACCOUNT_CREATED => $this->handleAccountCreated($event, $data),
            self::TYPE_ACCOUNT_VERIFIED => $this->handleAccountVerified($event, $data),
            self::TYPE_INVOICE_CREATED, self::TYPE_INVOICE_PAID => $this->handleInvoice($event, $data),
            self::TYPE_CALL_LOGGED => $this->handleCallLogged($event, $data),
            self::TYPE_CALL_COMPLETED => $this->handleCallCompleted($event, $data),
            default => 'unhandled',
        };

        CrmEventReceipt::query()->where('event_id', $eventId)->update(['result' => $result]);

        return ['status' => 202, 'result' => $result];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleLeadCaptured(array $event, array $data): string
    {
        $raw = is_string($data['phone_raw'] ?? null) ? $data['phone_raw'] : (is_string($data['phone'] ?? null) ? $data['phone'] : null);
        $canonical = PhoneNormalizer::canonical($raw);

        $payload = $data['payload'] ?? [];
        $payload = is_array($payload) ? $payload : [];

        foreach (['form', 'page_url'] as $extra) {
            if (isset($data[$extra])) {
                $payload[$extra] = $data[$extra];
            }
        }

        if (isset($data['sub_source']) && is_string($data['sub_source'])) {
            // Sub-source fields ride in the payload under a known key.
            $payload['sub_source'] = $data['sub_source'];
        }

        $result = $this->capture->capture(
            (string) ($event['source'] ?? ''),
            [
                'phone' => $canonical ?? (is_scalar($raw) ? (string) $raw : null),
                'name' => $data['name'] ?? null,
                'family' => $data['family'] ?? null,
                'customer_id' => isset($data['user_id']) ? $this->resolveCustomerId((int) $data['user_id']) : null,
                'captured_at' => $event['occurred_at'] ?? now(),
                'origin_ref' => (string) ($event['origin_id'] ?? ''),
                'payload' => $payload,
            ],
        );

        return match ($result->status) {
            CaptureResult::CAPTURED => 'captured',
            CaptureResult::RETURNING => 'returning',
            CaptureResult::ALREADY_CAPTURED => 'already_captured',
            CaptureResult::INVALID_PHONE => 'invalid_phone',
            default => 'refused',
        };
    }

    /**
     * Verification / payment updates arrive after the copy was made (11 #29).
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleLeadVerified(array $event, array $data, string $field, int $value): string
    {
        $originRef = (string) ($event['origin_id'] ?? ($data['origin_id'] ?? ''));
        $entry = LeadEntry::query()
            ->where('source_id', $this->sourceIdBySlug((string) ($event['source'] ?? '')))
            ->where('origin_ref', $originRef)
            ->first();

        if ($entry === null) {
            return 'unknown_entry';
        }

        $payload = $entry->payload ?? [];
        $payload[$field] = $value;
        $entry->fill(['payload' => $payload])->save();

        return 'updated';
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleAccountCreated(array $event, array $data): string
    {
        $phone = PhoneNormalizer::canonical(is_string($data['phone'] ?? null) ? $data['phone'] : null);
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;

        if ($userId === null) {
            return 'missing_user_id';
        }

        $customer = $this->resolveCustomerModel($userId, $phone);

        $customer->fill(array_filter([
            'name' => $data['name'] ?? null,
            'phone' => $phone ?? ($customer->phone ?? null),
            'account_status' => $data['status'] ?? null,
            'add_from' => $data['add_from'] ?? null,
            'is_crm_created' => isset($data['crm']) ? (bool) $data['crm'] : null,
            'created_by_operator_id' => $data['operator_id'] ?? null,
        ], static fn ($v) => $v !== null));

        if (! $customer->exists || $customer->isDirty()) {
            $customer->save();
        }

        // Link the lead contact to this account (03 §5 step 3).
        if ($phone !== null) {
            LeadContact::query()->where('phone', $phone)
                ->whereNull('customer_id')
                ->update(['customer_id' => $customer->id, 'customer_checked_at' => now()]);
        }

        return 'ok';
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleAccountVerified(array $event, array $data): string
    {
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;
        if ($userId === null) {
            return 'missing_user_id';
        }

        $customer = $this->resolveCustomerModel($userId, null);

        $customer->fill([
            'account_status' => 'verified',
            'last_login_at' => $event['occurred_at'] ?? now(),
        ])->save();

        return 'ok';
    }

    /**
     * Invoice.created / invoice.paid → the CRM invoice mirror.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleInvoice(array $event, array $data): string
    {
        $legacyId = (int) ($data['invoice_id'] ?? ($event['origin_id'] ?? 0));
        if ($legacyId === 0) {
            return 'missing_invoice_id';
        }

        $phone = PhoneNormalizer::canonical(is_string($data['phone'] ?? null) ? $data['phone'] : null);

        /** @var CrmInvoice $invoice */
        $invoice = CrmInvoice::query()->firstOrNew(['legacy_id' => $legacyId]);

        $invoice->fill([
            'phone' => $phone ?? $invoice->phone,
            'customer_id' => $phone !== null
                ? Customer::findByCanonicalPhone($phone)?->id
                : $invoice->customer_id,
            'tracking_code' => isset($data['trackingcode']) ? (int) $data['trackingcode'] : $invoice->tracking_code,
            'total' => Persian::toInt(is_string($data['total'] ?? null) ? $data['total'] : null),
            'discount' => $data['discount'] ?? $invoice->discount,
            'reagent_code' => $data['reagent_code'] ?? $invoice->reagent_code,
            'pay_type' => isset($data['pay_type']) ? (int) $data['pay_type'] : $invoice->pay_type,
            'invoice_date' => isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : $invoice->invoice_date,
            'status' => isset($data['status']) ? (int) $data['status'] : $invoice->status,
        ]);

        if ($invoice->isPaid() && $invoice->paid_at === null) {
            $invoice->paid_at = isset($data['paid_at'])
                ? Carbon::parse($data['paid_at'])
                : (isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : now());
        }

        $invoice->save();

        // Lines (product ids) — replace to stay consistent.
        if (! empty($data['products']) && is_array($data['products'])) {
            $invoice->lines()->delete();
            foreach ($data['products'] as $productId) {
                CrmInvoiceLine::create([
                    'crm_invoice_id' => $invoice->id,
                    'product_id' => (int) $productId,
                    'product_name' => is_array($data['product_names'] ?? null) ? ($data['product_names'][$productId] ?? null) : null,
                ]);
            }
        }

        return $event['type'] === self::TYPE_INVOICE_PAID ? 'paid' : 'ok';
    }

    /**
     * Call.logged → one row per ringing extension, deduped per call.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleCallLogged(array $event, array $data): string
    {
        $phone = PhoneNormalizer::canonical(is_string($data['phone'] ?? null) ? $data['phone'] : null)
            ?? ($data['phone'] ?? null);
        $uniqueid = (string) ($data['uniqueid'] ?? '');
        $exten = isset($data['exten']) ? (string) $data['exten'] : null;
        $direction = ($data['direction'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming';

        if ($uniqueid === '' || $exten === null) {
            return 'missing_call_identity';
        }

        $existing = CrmCallLog::query()
            ->where('uniqueid', $uniqueid)
            ->where('exten', $exten)
            ->where('type', $direction)
            ->first();

        if ($existing !== null) {
            return 'duplicate_call';
        }

        CrmCallLog::create([
            'phone' => is_string($phone) ? $phone : null,
            'phone_raw' => is_scalar($data['phone_raw'] ?? null) ? (string) $data['phone_raw'] : (is_string($data['phone'] ?? null) ? $data['phone'] : null),
            'exten' => $exten,
            'type' => $direction,
            'uniqueid' => $uniqueid,
            'accepted_call' => $direction === 'outgoing' ? $exten : ($data['accepted_exten'] ?? null),
            'called_at' => isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : now(),
        ]);

        return 'ok';
    }

    /**
     * Call.completed → fill duration + disposition on every ring row.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $data
     */
    private function handleCallCompleted(array $event, array $data): string
    {
        $uniqueid = (string) ($data['uniqueid'] ?? '');
        if ($uniqueid === '') {
            return 'missing_uniqueid';
        }

        $rows = CrmCallLog::query()->where('uniqueid', $uniqueid)->get();
        if ($rows->isEmpty()) {
            return 'unknown_call';
        }

        $answered = ($data['disposition'] ?? null) === 'ANSWERED';
        foreach ($rows as $row) {
            $row->fill([
                'call_time' => isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : $row->call_time,
                'status_asterisk' => is_string($data['disposition'] ?? null) ? $data['disposition'] : $row->status_asterisk,
                'cdr_verify' => true,
            ]);

            // The answering extension gets accepted_call (04 §5.1).
            if ($answered && $row->accepted_call === null) {
                $row->accepted_call = $row->exten;
            }

            $row->save();
        }

        return 'ok';
    }

    private function sourceIdBySlug(string $slug): ?int
    {
        return LeadSource::query()->where('slug', $slug)->value('id');
    }

    /** Map a legacy user_id to a CRM account, creating a stub when new. */
    private function resolveCustomerId(int $legacyUserId): int
    {
        return $this->resolveCustomerModel($legacyUserId, null)->id;
    }

    private function resolveCustomerModel(int $legacyUserId, ?string $phone): Customer
    {
        // In this build the customer id IS the legacy user id (1:1 mirror).
        $customer = Customer::query()->find($legacyUserId);

        if ($customer === null) {
            $customer = $phone !== null ? Customer::findByCanonicalPhone($phone) : null;
        }

        if ($customer === null) {
            // Create a stub account so the link is never lost.
            $customer = new Customer;
            $customer->id = $legacyUserId;
            $customer->name = '';
            $customer->phone = $phone;
            $customer->save();
        }

        return $customer;
    }
}
