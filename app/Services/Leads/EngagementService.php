<?php

namespace App\Services\Leads;

use App\Models\CrmReferral;
use App\Models\LeadContact;
use App\Models\LeadEntry;
use App\Models\LeadReferralContext;
use App\Models\LeadSource;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Engagement tags (spec 05 §8): «اولین تعامل» (first), «تعامل مجدد»
 * (latest) and «منبع ارجاع» (trigger). Engagement events are every
 * stored entry plus every identified UTM visit (the UTM half lands with
 * the UtmService tables; entries are always available).
 */
class EngagementService
{
    /** One engagement event: [label, at, ref]. @return list<array{label: string, at: Carbon, ref: string}> */
    public function eventsFor(LeadContact $contact): array
    {
        $events = [];

        $entries = LeadEntry::query()
            ->where('contact_id', $contact->id)
            ->where('invalid_phone', false)
            ->with('source')
            ->orderBy('captured_at')
            ->get();

        foreach ($entries as $entry) {
            $events[] = [
                'label' => $entry->source->name ?? $entry->source->slug ?? 'lead',
                'at' => $entry->captured_at ?? Carbon::parse($entry->created_at),
                'ref' => 'entry:'.$entry->id,
            ];
        }

        // Identified UTM visits count as engagements too (05 §8). The
        // utm_records table ships with the UtmService part of the build.
        if (Schema::hasTable('utm_records')) {
            $utmEvents = $this->utmEvents($contact);
            $events = [...$events, ...$utmEvents];
            usort($events, static fn ($a, $b) => $a['at'] <=> $b['at']);
        }

        return $events;
    }

    /**
     * The current first/latest engagement of a profile (no freezing).
     *
     * @return array{first: ?array{label: string, at: Carbon, ref: string}, latest: ?array{label: string, at: Carbon, ref: string}}
     */
    public function profile(LeadContact $contact): array
    {
        $events = $this->eventsFor($contact);
        if ($events === []) {
            return ['first' => null, 'latest' => null];
        }

        $first = $events[0];
        $latest = $events[count($events) - 1];

        return [
            'first' => $first,
            'latest' => $latest['ref'] !== $first['ref'] ? $latest : null,
        ];
    }

    /**
     * Freeze the engagement tags of a live assignment: first/latest as
     * of the referral time, trigger = the entry, status complete.
     */
    public function captureReferralContext(CrmReferral $referral, LeadSource $source, LeadEntry $entry): LeadReferralContext
    {
        $contact = $entry->contact;
        $referredAt = $referral->referred_at ?? now();

        $before = array_filter(
            $this->eventsFor($contact),
            static fn (array $event) => $event['at']->lte($referredAt),
        );

        $first = $before === [] ? null : $before[0];
        $latest = $before === [] ? null : $before[count($before) - 1];

        return LeadReferralContext::query()->updateOrCreate(
            ['crm_referral_id' => $referral->id],
            [
                'contact_id' => $contact->id,
                'first_label' => $first['label'] ?? null,
                'first_at' => $first['at'] ?? null,
                'first_ref' => $first['ref'] ?? null,
                'latest_label' => $latest !== null && $latest['ref'] !== $first['ref'] ? $latest['label'] : null,
                'latest_at' => $latest !== null && $latest['ref'] !== $first['ref'] ? $latest['at'] : null,
                'latest_ref' => $latest !== null && $latest['ref'] !== $first['ref'] ? $latest['ref'] : null,
                'trigger_label' => $source->name,
                'trigger_at' => $entry->captured_at,
                'trigger_ref' => 'entry:'.$entry->id,
                'status' => LeadReferralContext::STATUS_COMPLETE,
                'referred_at' => $referredAt,
                'snapshot_at' => now(),
            ],
        );
    }

    /**
     * Rebuild a context on first display for a referral written without
     * the hook (status inferred), or unknown when no evidence predates it.
     */
    public function rebuildReferralContext(CrmReferral $referral, LeadContact $contact): LeadReferralContext
    {
        $referredAt = $referral->referred_at ?? now();

        $before = array_filter(
            $this->eventsFor($contact),
            static fn (array $event) => $event['at']->lte($referredAt),
        );

        $first = $before === [] ? null : $before[0];
        $latest = $before === [] ? null : $before[count($before) - 1];

        return LeadReferralContext::query()->updateOrCreate(
            ['crm_referral_id' => $referral->id],
            [
                'contact_id' => $contact->id,
                'first_label' => $first['label'] ?? null,
                'first_at' => $first['at'] ?? null,
                'first_ref' => $first['ref'] ?? null,
                'latest_label' => $latest !== null && $latest['ref'] !== $first['ref'] ? $latest['label'] : null,
                'latest_at' => $latest !== null && $latest['ref'] !== $first['ref'] ? $latest['at'] : null,
                'latest_ref' => $latest !== null && $latest['ref'] !== $first['ref'] ? $latest['ref'] : null,
                'trigger_label' => $referral->lead_type,
                'trigger_at' => null,
                'trigger_ref' => null,
                'status' => $before === [] ? LeadReferralContext::STATUS_UNKNOWN : LeadReferralContext::STATUS_INFERRED,
                'referred_at' => $referredAt,
                'snapshot_at' => now(),
            ],
        );
    }

    /**
     * Trigger label for a referral row: the entry, the invoice (for the
     * unpaid-invoices source) or the segment — used by «منبع ارجاع».
     *
     * @return array{label: string, ref: string}|null
     */
    public function triggerOf(CrmReferral $referral): ?array
    {
        $context = $referral->context;

        if ($context !== null && $context->trigger_label !== null) {
            return ['label' => $context->trigger_label, 'ref' => $context->trigger_ref ?? ''];
        }

        return null;
    }

    /**
     * UTM visit events (label `UTM · <link name>`, at = created_at
     * converted from UTC to Tehran — spec 05 §8).
     *
     * @return list<array{label: string, at: Carbon, ref: string}>
     */
    private function utmEvents(LeadContact $contact): array
    {
        $canonical = PhoneNormalizer::canonical($contact->phone);
        if ($canonical === null) {
            return [];
        }

        $rows = \DB::table('utm_records')
            ->where(function ($query) use ($contact, $canonical) {
                $query->where('phone', $canonical)
                    ->orWhere('user_id', $contact->customer_id);
            })
            ->whereNotNull('identified_at')
            ->orderBy('created_at')
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'label' => 'UTM · '.($row->display_name ?? ($row->utm_campaign ?? 'campaign')),
                'at' => Carbon::parse($row->created_at)->timezone(config('crm.lead_service.timezone', 'Asia/Tehran')),
                'ref' => 'utm:'.$row->id,
            ];
        }

        return $events;
    }
}
