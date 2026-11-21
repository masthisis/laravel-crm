<?php

namespace App\Services\Leads;

use App\Models\Customer;
use App\Models\LeadLegacyCursor;
use App\Models\LeadSource;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Legacy copy tool (spec 05 §7): copies every legacy lead table in
 * chunks by id cursor. Resumable and idempotent (entries are keyed by
 * (source, origin_ref)). Reads the read-only `legacy` connection; when
 * it is not enabled the service refuses politely.
 */
class LegacyCopyService
{
    private ?LeadCaptureService $capture = null;

    public function withCapture(LeadCaptureService $capture): self
    {
        $this->capture = $capture;

        return $this;
    }

    private function captureService(): LeadCaptureService
    {
        return $this->capture ??= app(LeadCaptureService::class);
    }

    /**
     * Adapter field maps (legacy column → entry field; the rest go to the
     * payload) — spec 05 §7.
     *
     * @return array<string, array{table: string, phone?: string, name?: string, family?: string, user?: string, captured_at?: string, payload: array<string, string>, created_by?: string}>
     */
    public static function adapters(): array
    {
        return [
            'starttraining' => [
                'table' => 'rayan_starttraining',
                'phone' => 'phone', 'name' => 'name', 'user' => 'user_id', 'captured_at' => 'date',
                'payload' => ['category' => 'category', 'verified' => 'status'],
            ],
            'ask' => [
                'table' => 'rayan_ask',
                'phone' => 'phone', 'name' => 'name', 'user' => 'user_id', 'captured_at' => 'date',
                'payload' => ['learning' => 'learning', 'datingtime' => 'datingtime'],
            ],
            'lead_from_admin' => [
                'table' => 'rayan_lead_from_admin',
                'phone' => 'phone', 'name' => 'name', 'captured_at' => 'created_at', 'created_by' => 'user_id',
                'payload' => ['lead_type' => 'lead_type', 'from_user' => 'from_user'],
            ],
            'requestcall' => [
                'table' => 'rayan_requestcall',
                'phone' => 'phone', 'name' => 'name', 'family' => 'family', 'user' => 'user_id', 'captured_at' => 'date',
                'payload' => ['verified' => 'status', 'comment' => 'commentss'],
            ],
            'consult_users' => [
                'table' => 'rayan_consult_users',
                'phone' => 'phone', 'name' => 'name', 'user' => 'user_id', 'captured_at' => 'date',
                'payload' => ['consult_main' => 'consult_main', 'consult_sub' => 'consult_sub', 'consult_type' => 'consult_type', 'consult_status' => 'consult_status'],
            ],
            'webinar' => [
                'table' => 'rayan_webinar_users',
                'user' => 'user_id', 'captured_at' => 'created_at',
                // phone and name come from the linked user
                'payload' => ['webinar_id' => 'webinar_id', 'new_user' => 'new_user'],
            ],
            'campaign' => [
                'table' => 'rayan_campaign',
                'phone' => 'phone', 'name' => 'name', 'user' => 'user_id', 'captured_at' => 'date',
                'payload' => ['page' => 'page'],
            ],
            'oncalls' => [
                'table' => 'rayan_oncalls',
                'phone' => 'phone', 'captured_at' => 'date',
                'payload' => ['category' => 'category', 'call_status' => 'status', 'operator_id' => 'operator_id'],
            ],
            'authexchange' => [
                'table' => 'rayan_authexchange',
                'phone' => 'phone', 'name' => 'name', 'user' => 'user_id', 'captured_at' => 'date',
                'payload' => ['descriptions' => 'descriptions', 'status' => 'status'],
            ],
        ];
    }

    /** Source slug per adapter (05 §2 → 05 §7). */
    public static function sourceFor(string $adapter): string
    {
        return match ($adapter) {
            'webinar' => 'webinar',
            'authexchange' => 'authexchange',
            default => $adapter,
        };
    }

    public function legacyEnabled(): bool
    {
        return (bool) config('crm.legacy.enabled');
    }

    /**
     * Run one step (up to $chunk rows) for an adapter.
     *
     * @return array{copied: int, done: bool, last_id: int}
     */
    public function step(string $adapter, int $chunk = 1000): array
    {
        if (! $this->legacyEnabled()) {
            throw new RuntimeException('Legacy connection is disabled (set LEGACY_DB_ENABLED=true and LEGACY_DB_*)');
        }

        $definition = self::adapters()[$adapter] ?? null;
        if ($definition === null) {
            throw new RuntimeException("Unknown adapter: {$adapter}");
        }

        $cursor = LeadLegacyCursor::query()->firstOrCreate(['adapter' => $adapter], ['last_origin_id' => 0]);
        $table = $definition['table'];
        $connection = config('crm.legacy.connection', 'legacy');

        $rows = DB::connection($connection)
            ->table($table)
            ->where('id', '>', $cursor->last_origin_id)
            ->orderBy('id')
            ->limit($chunk)
            ->get();

        $sourceSlug = self::sourceFor($adapter);
        $source = LeadSource::query()->where('slug', $sourceSlug)->first();
        if ($source === null) {
            throw new RuntimeException("Source '{$sourceSlug}' does not exist — seed the system sources first");
        }

        $captured = 0;
        $lastId = (int) $cursor->last_origin_id;

        foreach ($rows as $row) {
            $lastId = (int) $row->id;
            $record = $this->rowToRecord($definition, $row, $connection);

            $result = $this->captureService()->capture($source, $record + ['origin_ref' => (string) $row->id]);
            if ($result->isCaptured()) {
                $captured++;
            }
        }

        $done = count($rows) < $chunk;

        $cursor->fill([
            'last_origin_id' => $lastId,
            'copied' => $cursor->copied + $captured,
            'finished_at' => $done ? now() : null,
        ])->save();

        return ['copied' => $captured, 'done' => $done, 'last_id' => $lastId];
    }

    /**
     * Map one legacy row to a capture record (05 §7 adapter maps). The
     * webinar adapter resolves phone/name from the linked user.
     *
     * @return array<string, mixed>
     */
    private function rowToRecord(array $definition, object $row, string $connection): array
    {
        $record = ['payload' => []];

        // Extra columns → payload, matching the adapter map.
        foreach ($definition['payload'] as $field => $column) {
            $record['payload'][$field] = isset($row->{$column}) ? $row->{$column} : null;
        }

        $record['phone'] = isset($row->{$definition['phone'] ?? ''}) ? $row->{$definition['phone']} : null;

        if ($record['phone'] === null && ($definition['user'] ?? null) !== null) {
            // e.g. rayan_webinar_users has no phone — join the user.
            $user = DB::connection($connection)
                ->table('rayan_users')
                ->where('id', $row->{$definition['user']})
                ->first();
            if ($user !== null) {
                $record['phone'] = $user->phone ?? null;
                $record['name'] = trim(($user->name ?? '').' '.($user->family ?? '')) ?: null;
                $record['customer_id'] = $this->lookupCustomerId($record['phone']);
            }
        }

        if (($definition['name'] ?? null) !== null && isset($row->{$definition['name']})) {
            $record['name'] = $row->{$definition['name']};
        }

        if (($definition['family'] ?? null) !== null && isset($row->{$definition['family']})) {
            $record['family'] = $row->{$definition['family']};
        }

        if (($definition['captured_at'] ?? null) !== null && isset($row->{$definition['captured_at']})) {
            $record['captured_at'] = $row->{$definition['captured_at']};
        }

        return $record;
    }

    /** Match the canonical phone to a CRM account (best effort). */
    private function lookupCustomerId(?string $phone): ?int
    {
        $canonical = PhoneNormalizer::canonical($phone);
        if ($canonical === null) {
            return null;
        }

        return Customer::findByCanonicalPhone($canonical)?->id;
    }
}
