<?php

namespace App\Services\Leads;

use App\Models\Customer;
use App\Models\LeadContact;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Date;

/**
 * Batched, cached account lookups by phone (spec 05 §3 step 2 / 03 §5
 * step 3): match across phone / phone2 / phone3 in that column priority,
 * cache a miss for 24 h so list views stay cheap.
 */
class LegacyUserLinker
{
    public function __construct(
        private readonly int $missCacheHours = 24,
    ) {}

    public function findCustomerId(string $canonical): ?int
    {
        $customer = Customer::findByCanonicalPhone($canonical);

        return $customer?->id;
    }

    /**
     * Fill customer links on contacts whose last miss is older than the
     * cache window. Returns the number of newly linked contacts.
     *
     * @param  iterable<LeadContact>  $contacts
     */
    public function linkMissing(iterable $contacts): int
    {
        $threshold = Date::now()->subHours($this->missCacheHours);
        $linked = 0;

        foreach ($contacts as $contact) {
            if ($contact->customer_id !== null) {
                continue;
            }

            if ($contact->customer_checked_at !== null && $contact->customer_checked_at->gt($threshold)) {
                continue; // recent miss, cached
            }

            $found = $this->findCustomerId(PhoneNormalizer::canonical($contact->phone) ?? $contact->phone);
            $contact->customer_checked_at = now();

            if ($found !== null) {
                $contact->customer_id = $found;
                $contact->save();
                $linked++;
            } else {
                $contact->save();
            }
        }

        return $linked;
    }
}
