<?php

namespace App\Services\Intake;

use App\Models\LeadLegacyCursor;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Polling fallback (spec 03 §4): every producer table is polled by id
 * cursor; status changes (which an id cursor cannot see) are polled by
 * update_date with a few-minute overlap. Events and polls share the
 * idempotency key, so both paths can run together safely.
 */
class PollLegacyService
{
    /** Adapter tables polled by id cursor: [adapter, table, emitted event type, source]. */
    public static function cursors(): array
    {
        return [
            ['starttraining', 'rayan_starttraining', EventIntakeService::TYPE_LEAD_CAPTURED, 'starttraining'],
            ['ask', 'rayan_ask', EventIntakeService::TYPE_LEAD_CAPTURED, 'ask'],
            ['requestcall', 'rayan_requestcall', EventIntakeService::TYPE_LEAD_CAPTURED, 'requestcall'],
            ['consult_users', 'rayan_consult_users', EventIntakeService::TYPE_LEAD_CAPTURED, 'consult_users'],
            ['webinar', 'rayan_webinar_users', EventIntakeService::TYPE_LEAD_CAPTURED, 'webinar'],
            ['campaign', 'rayan_campaign', EventIntakeService::TYPE_LEAD_CAPTURED, 'campaign'],
            ['lead_from_admin', 'rayan_lead_from_admin', EventIntakeService::TYPE_LEAD_CAPTURED, 'lead_from_admin'],
            ['oncalls', 'rayan_oncalls', EventIntakeService::TYPE_LEAD_CAPTURED, 'oncalls'],
            ['authexchange', 'rayan_authexchange', EventIntakeService::TYPE_LEAD_CAPTURED, 'authexchange'],
            ['users', 'rayan_users', EventIntakeService::TYPE_ACCOUNT_CREATED, 'users'],
            ['orders', 'rayan_prefactor_group', EventIntakeService::TYPE_INVOICE_CREATED, 'orders'],
            ['calls', 'rayan_crm_call_log', EventIntakeService::TYPE_CALL_LOGGED, 'pbx'],
            ['referrals', 'rayan_lead_referal', EventIntakeService::TYPE_LEAD_CAPTURED, 'referrals'],
        ];
    }

    /** Status-change windows: [adapter key, table, column, event type]. */
    public static function statusWindows(): array
    {
        return [
            ['starttraining_status', 'rayan_starttraining', 'status', EventIntakeService::TYPE_LEAD_VERIFIED, 1],
            ['requestcall_status', 'rayan_requestcall', 'status', EventIntakeService::TYPE_LEAD_VERIFIED, 2],
            ['consult_status', 'rayan_consult_users', 'consult_status', EventIntakeService::TYPE_LEAD_PAID, 2],
            ['orders_paid', 'rayan_prefactor_group', 'status', EventIntakeService::TYPE_INVOICE_PAID, 2],
            ['calls_cdr', 'rayan_crm_call_log', 'cdr_verify', EventIntakeService::TYPE_CALL_COMPLETED, 1],
        ];
    }

    public function __construct(
        private readonly EventIntakeService $intake,
        private readonly int $chunk = 1000,
        private readonly int $overlapMinutes = 5,
    ) {}

    public function legacyEnabled(): bool
    {
        return (bool) config('crm.legacy.enabled');
    }

    /**
     * One poll pass over every cursor and status window.
     *
     * @return array<string, int>
     */
    public function pollAll(): array
    {
        $counts = [];

        foreach (self::cursors() as [$adapter, $table, $type, $source]) {
            $counts[$adapter] = $this->pollIdCursor($adapter, $table, $type, $source);
        }

        foreach (self::statusWindows() as [$key, $table, $column, $type, $value]) {
            $counts[$key] = $this->pollStatusWindow($key, $table, $column, $type, $value);
        }

        return $counts;
    }

    /**
     * Poll one table by id cursor and feed the rows through the intake.
     */
    public function pollIdCursor(string $adapter, string $table, string $type, string $source): int
    {
        // Partial deployments: a missing producer table is skipped, not fatal.
        if (! Schema::connection($this->connection())->hasTable($table)) {
            return 0;
        }

        $cursor = LeadLegacyCursor::query()->firstOrCreate(['adapter' => 'poll:'.$adapter], ['last_origin_id' => 0]);

        $rows = DB::connection($this->connection())
            ->table($table)
            ->where('id', '>', $cursor->last_origin_id)
            ->orderBy('id')
            ->limit($this->chunk)
            ->get();

        $processed = 0;
        foreach ($rows as $row) {
            $this->intake->accept($this->envelope($type, $source, $table, $row, $this->rowToData($table, $row)));
            $processed++;
        }

        if ($rows->isNotEmpty()) {
            $cursor->fill([
                'last_origin_id' => (int) $rows->last()->id,
                'copied' => $cursor->copied + $processed,
            ])->save();
        }

        return $processed;
    }

    /**
     * Status changes cannot be found by id cursor: poll by the
     * ON-UPDATE column with an overlap window (03 §4).
     */
    public function pollStatusWindow(string $key, string $table, string $column, string $type, int $value): int
    {
        if (! Schema::connection($this->connection())->hasTable($table)) {
            return 0;
        }

        $cursor = LeadLegacyCursor::query()->firstOrCreate(['adapter' => 'poll:'.$key], ['last_origin_id' => 0]);
        $since = Carbon::createFromTimestamp((int) $cursor->last_origin_id)->subMinutes($this->overlapMinutes);
        $now = now();
        $updateColumn = $this->updateColumnFor($table);

        $rows = DB::connection($this->connection())
            ->table($table)
            ->where($column, $value)
            ->where($updateColumn, '>=', $since)
            ->where($updateColumn, '<=', $now)
            ->orderBy('id')
            ->limit($this->chunk)
            ->get();

        $processed = 0;
        foreach ($rows as $row) {
            $this->intake->accept($this->envelope($type, $this->sourceForStatus($key), $table, $row, ['status' => $value]));
            $processed++;
        }

        // Remember the high-water mark of this window.
        $cursor->fill(['last_origin_id' => $now->timestamp])->save();

        return $processed;
    }

    private function connection(): string
    {
        $connection = config('crm.legacy.connection', 'legacy');
        if (! $this->legacyEnabled()) {
            throw new RuntimeException('Legacy connection is disabled (set LEGACY_DB_ENABLED=true)');
        }

        return $connection;
    }

    private function updateColumnFor(string $table): string
    {
        return match ($table) {
            'rayan_prefactor_group' => 'update_date',
            'rayan_crm_call_log' => 'date_update',
            default => 'date_update',
        };
    }

    private function sourceForStatus(string $key): string
    {
        return str_contains($key, 'orders') ? 'orders' : (str_contains($key, 'calls') ? 'pbx' : explode('_', $key)[0]);
    }

    /**
     * Build the event envelope for a polled row (spec 03 §1): occurred_at
     * is the row's own date (Tehran wall clock), idempotency_key =
     * <source>:<origin_id>.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $type, string $source, string $table, object $row, array $data): array
    {
        $occurredAt = $row->date ?? $row->created_at ?? null;

        return [
            'event_id' => Str::uuid()->toString(),
            'type' => $type,
            'occurred_at' => $occurredAt !== null ? Carbon::parse($occurredAt)->toIso8601String() : now()->toIso8601String(),
            'producer' => 'legacy-site',
            'source' => $source,
            'origin_table' => $table,
            'origin_id' => (int) $row->id,
            'idempotency_key' => $source.':'.$row->id,
            'data' => $data,
        ];
    }

    /**
     * Map a legacy row to the event's data payload (phones raw + canonical).
     *
     * @return array<string, mixed>
     */
    private function rowToData(string $table, object $row): array
    {
        $rawPhone = $row->phone ?? null;
        $data = [
            'phone_raw' => $rawPhone,
            'phone' => PhoneNormalizer::canonical(is_string($rawPhone) ? $rawPhone : null),
        ];

        if ($table === 'rayan_users') {
            $data += [
                'user_id' => (int) $row->id,
                'name' => $row->name ?? null,
                'family' => $row->family ?? null,
                'status' => $row->status ?? null,
                'add_from' => $row->add_from ?? null,
                'crm' => $row->crm ?? null,
                'operator_id' => $row->operator_id ?? null,
            ];
        } elseif ($table === 'rayan_webinar_users') {
            $data += ['user_id' => isset($row->user_id) ? (int) $row->user_id : null];
        } else {
            $data += [
                'name' => $row->name ?? null,
                'family' => $row->family ?? null,
                'user_id' => isset($row->user_id) ? (int) $row->user_id : null,
            ];

            if (isset($row->category)) {
                $data['sub_source'] = $row->category;
            }

            if (isset($row->page)) {
                $data['sub_source'] = $row->page;
            }

            if (isset($row->lead_type)) {
                $data['sub_source'] = $row->lead_type;
            }

            if ($table === 'rayan_prefactor_group') {
                $data += [
                    'invoice_id' => (int) $row->id,
                    'total' => $row->total ?? null,
                    'discount' => $row->discount ?? null,
                    'reagent_code' => $row->reagent_code ?? null,
                    'pay_type' => $row->pay_type ?? null,
                    'status' => $row->status ?? null,
                    'trackingcode' => $row->trackingcode ?? null,
                ];
            }

            if ($table === 'rayan_crm_call_log') {
                $data += [
                    'uniqueid' => $row->uniqueid ?? null,
                    'exten' => $row->exten ?? null,
                    'direction' => $row->type ?? 'incoming',
                    'phone_raw' => $row->phone_asterisk_cdr ?? $rawPhone,
                ];
            }
        }

        return $data;
    }
}
