<?php

namespace App\Services\Leads;

use App\Models\LeadContact;
use App\Models\LeadContactSource;
use App\Models\LeadEntry;
use App\Models\LeadEntryFacet;
use App\Models\LeadSource;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;

/**
 * The single ingestion path (spec 05 §3). Hooks, the legacy copy, file
 * imports and events all end here.
 *
 * Per row:
 *  1. normalise the phone (invalid ⇒ entry flagged, no contact, never dropped);
 *  2. find or create the contact by canonical phone;
 *  3. link the site account (explicit id, else variant lookup with a
 *     24 h miss cache);
 *  4. insert the entry — UNIQUE(source_id, origin_ref) makes repeats a
 *     no-op;
 *  5. mark returning when the contact already had an entry in this source;
 *  6. update the per-source summary;
 *  7. write the configured facets.
 */
class LeadCaptureService
{
    public function __construct(
        private readonly LegacyUserLinker $userLinker,
    ) {}

    /**
     * Row shape: phone (raw or canonical), name?, family?, customer_id?,
     * captured_at?, origin_ref?, payload?, created_by?
     *
     * @param  array<string, mixed>  $row
     */
    public function capture(string|LeadSource $source, array $row): CaptureResult
    {
        $source = $source instanceof LeadSource ? $source : LeadSource::query()->where('slug', $source)->first();

        if ($source === null) {
            return new CaptureResult(CaptureResult::REFUSED, reason: 'unknown_source');
        }

        // Only stored sources accept entries (05 §3).
        if ($source->driver !== LeadSource::DRIVER_STORED) {
            return new CaptureResult(CaptureResult::REFUSED, reason: 'source_not_stored');
        }

        $rawPhone = $row['phone'] ?? null;
        $canonical = PhoneNormalizer::canonical(is_scalar($rawPhone) ? (string) $rawPhone : null);

        $capturedAt = isset($row['captured_at'])
            ? Carbon::parse($row['captured_at'])
            : now();

        $payload = $row['payload'] ?? [];
        $payload = is_array($payload) ? $payload : [];

        if ($canonical === null) {
            // Keep the entry, flagged — leads are never dropped silently.
            $entry = LeadEntry::create([
                'source_id' => $source->id,
                'contact_id' => null,
                'origin_ref' => isset($row['origin_ref']) ? (string) $row['origin_ref'] : null,
                'name' => $row['name'] ?? null,
                'payload' => $payload,
                'captured_at' => $capturedAt,
                'import_batch_id' => $row['import_batch_id'] ?? null,
                'raw_phone' => is_scalar($rawPhone) ? (string) $rawPhone : null,
                'invalid_phone' => true,
                'invalid_reason' => 'invalid_phone_format',
            ]);

            return new CaptureResult(CaptureResult::INVALID_PHONE, $entry->id, null, null, 'invalid_phone_format');
        }

        $contact = $this->resolveContact($source, $canonical, $row, $capturedAt);

        $hadEntryBefore = LeadEntry::query()
            ->where('source_id', $source->id)
            ->where('contact_id', $contact->id)
            ->exists();

        // Idempotent insert: (source_id, origin_ref).
        $originRef = $row['origin_ref'] ?? null;
        $exists = $originRef !== null
            && LeadEntry::query()->forOrigin($source->id, $originRef)->exists();

        if ($exists) {
            return new CaptureResult(CaptureResult::ALREADY_CAPTURED, null, $contact->id, $canonical, 'duplicate_origin');
        }

        $entry = LeadEntry::create([
            'source_id' => $source->id,
            'contact_id' => $contact->id,
            'origin_ref' => isset($row['origin_ref']) ? (string) $row['origin_ref'] : null,
            'name' => $row['name'] ?? null,
            'payload' => $payload,
            'captured_at' => $capturedAt,
            'import_batch_id' => $row['import_batch_id'] ?? null,
            'raw_phone' => is_scalar($rawPhone) ? (string) $rawPhone : null,
            'returning' => $hadEntryBefore,
        ]);

        $this->writeContactSource($contact, $source, $capturedAt);
        $this->writeFacets($entry, $source, $payload);

        return new CaptureResult(
            $hadEntryBefore ? CaptureResult::RETURNING : CaptureResult::CAPTURED,
            $entry->id,
            $contact->id,
            $canonical,
        );
    }

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @return list<CaptureResult>
     */
    public function captureMany(string|LeadSource $source, iterable $rows): array
    {
        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->capture($source, $row);
        }

        return $results;
    }

    /**
     * Find or create the contact; widen first/last seen, keep an existing
     * name, never overwrite an existing account link (05 §3 step 2).
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveContact(LeadSource $source, string $canonical, array $row, Carbon $capturedAt): LeadContact
    {
        /** @var LeadContact|null $contact */
        $contact = LeadContact::query()->where('phone', $canonical)->first();

        if ($contact === null) {
            $customerId = $row['customer_id'] ?? null;

            if ($customerId === null) {
                // Look up the site account unless a miss was cached < 24 h ago.
                $customerId = $this->userLinker->findCustomerId($canonical);
            }

            $contact = LeadContact::create([
                'phone' => $canonical,
                'customer_id' => $customerId,
                'customer_checked_at' => now(),
                'name' => $row['name'] ?? null,
                'family' => $row['family'] ?? null,
                'first_seen_at' => $capturedAt,
                'last_seen_at' => $capturedAt,
            ]);

            return $contact;
        }

        $updates = [];
        $lastSeen = $contact->last_seen_at !== null ? Carbon::parse($contact->last_seen_at) : null;

        if ($lastSeen === null || $capturedAt->gt($lastSeen)) {
            $updates['last_seen_at'] = $capturedAt;
        }

        if ($contact->first_seen_at === null || $capturedAt->lt($contact->first_seen_at)) {
            $updates['first_seen_at'] = $capturedAt;
        }

        if (empty($contact->name) && ! empty($row['name'])) {
            $updates['name'] = $row['name'];
        }

        if (empty($contact->family) && ! empty($row['family'])) {
            $updates['family'] = $row['family'];
        }

        if ($contact->customer_id === null && ! empty($row['customer_id'])) {
            $updates['customer_id'] = $row['customer_id'];
        }

        $contact->fill($updates);

        // Re-check the account link when the last miss is older than 24 h.
        if ($contact->customer_id === null) {
            $shouldCheck = $contact->customer_checked_at === null
                || $contact->customer_checked_at->lt(now()->subDay());

            if ($shouldCheck) {
                $found = $this->userLinker->findCustomerId($canonical);
                if ($found !== null) {
                    $contact->customer_id = $found;
                }
                $contact->customer_checked_at = now();
            }
        }

        if ($contact->isDirty()) {
            $contact->save();
        }

        return $contact;
    }

    /** Upsert the per-person, per-source summary (05 §3 step 4). */
    private function writeContactSource(LeadContact $contact, LeadSource $source, Carbon $capturedAt): void
    {
        /** @var LeadContactSource|null $summary */
        $summary = LeadContactSource::query()
            ->where('contact_id', $contact->id)
            ->where('source_id', $source->id)
            ->first();

        if ($summary === null) {
            LeadContactSource::create([
                'contact_id' => $contact->id,
                'source_id' => $source->id,
                'first_at' => $capturedAt,
                'last_at' => $capturedAt,
                'entries' => 1,
            ]);

            return;
        }

        $summary->fill([
            'entries' => $summary->entries + 1,
            'last_at' => ($summary->last_at !== null && $summary->last_at->gt($capturedAt)) ? $summary->last_at : $capturedAt,
        ]);

        $firstAt = $summary->first_at;
        if ($firstAt === null || $capturedAt->lt($firstAt)) {
            $summary->first_at = $capturedAt;
        }

        $summary->save();
    }

    /** Facets for the source's configured filter fields (05 §3 step 6). */
    private function writeFacets(LeadEntry $entry, LeadSource $source, array $payload): void
    {
        foreach ($source->facetFields() as $field) {
            $value = $payload[$field] ?? null;

            LeadEntryFacet::create([
                'entry_id' => $entry->id,
                'source_id' => $source->id,
                'field' => $field,
                'value' => $value === null ? null : mb_substr((string) $value, 0, 255),
            ]);
        }
    }
}
