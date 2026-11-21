<?php

namespace App\Services\Leads;

use App\Models\CrmReferral;
use App\Models\LeadAssignment;
use App\Models\LeadContact;
use App\Models\LeadEntry;
use App\Models\LeadEvent;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assignment engine (spec 05 §5) + CRM follow-up events (§9).
 *
 * Per batch: re-check targets, keep one row per phone (the latest entry
 * is the trigger), ask for confirmation when the phone was assigned in
 * the last 30 days unless force, distribute single / round_robin, and in
 * one transaction write the referral, the assignment history, the owner
 * update and the frozen engagement context.
 */
class ReferralService
{
    public function __construct(
        private readonly EngagementService $engagement,
        private readonly int $duplicateWindowDays = 30,
        private readonly int $maxTargets = 2000,
    ) {}

    /** @return array<int, array{id: int, name: ?string, exten: ?string}> */
    public function eligibleOperators(): array
    {
        return User::query()->eligibleOperators()
            ->get(['id', 'name', 'exten'])
            ->map(static fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'exten' => $u->operatorCode(),
            ])
            ->all();
    }

    /**
     * Assign the given entries to operators.
     *
     * @param  list<int>  $entryIds  selected entry ids (re-checked server side)
     * @param  list<int>  $operatorIds  in choice order (round robin uses it)
     * @param  string  $strategy  single | round_robin
     * @param  string|null  $note  admin note ≤ 255 (referral description keeps 100)
     * @param  bool  $force  re-assign despite the 30-day duplicate window
     * @return array{status: string, assigned?: int, by_operator?: array<string, int>, duplicates?: list<array<string, mixed>>, count?: int}
     */
    public function assign(
        LeadSource|int|string $source,
        array $entryIds,
        array $operatorIds,
        string $strategy = LeadAssignment::STRATEGY_SINGLE,
        ?string $note = null,
        bool $force = false,
        ?int $assignedBy = null,
    ): array {
        $source = $source instanceof LeadSource ? $source : LeadSource::query()->where('slug', $source)->firstOrFail();

        if (count($entryIds) > $this->maxTargets) {
            throw new RuntimeException('Too many targets (max '.$this->maxTargets.')');
        }

        $strategy = in_array($strategy, [LeadAssignment::STRATEGY_SINGLE, LeadAssignment::STRATEGY_ROUND_ROBIN], true)
            ? $strategy
            : LeadAssignment::STRATEGY_SINGLE;

        // 1. Eligible operators only.
        $operators = User::query()->eligibleOperators()->whereIn('id', $operatorIds)->get();
        if ($operators->isEmpty()) {
            throw new RuntimeException('No eligible operators selected (active, may receive referrals, with an extension)');
        }

        // 2. Targets re-checked: the entries must still exist; entries
        //    whose contact already bought (paid invoice) since capture
        //    are still assignable — the admin decides.
        $entries = LeadEntry::query()
            ->whereIn('id', $entryIds)
            ->whereHas('contact')
            ->with('contact')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();

        if ($entries->isEmpty()) {
            throw new RuntimeException('No valid targets');
        }

        // 3. Unique by phone: keep the latest entry as the trigger.
        $targets = [];
        foreach ($entries as $entry) {
            $phone = (string) $entry->contact->phone;
            if (! isset($targets[$phone])) {
                $targets[$phone] = $entry;
            }
        }

        // 4. Duplicate window: 30 days without force → confirm first.
        if (! $force) {
            $duplicates = $this->findDuplicates(array_keys($targets));
            if ($duplicates !== []) {
                return [
                    'status' => 'confirm',
                    'duplicates' => $duplicates,
                    'count' => count($duplicates),
                ];
            }
        }

        // 5. Distribute.
        $operatorList = $operators->values();
        $assigned = [];
        $byOperator = [];

        foreach (array_values($targets) as $index => $entry) {
            /** @var User $operator */
            $operator = $strategy === LeadAssignment::STRATEGY_ROUND_ROBIN
                ? $operatorList[$index % $operatorList->count()]
                : $operatorList[0];

            $referral = $this->assignOne($source, $entry, $operator, $strategy, $note, $assignedBy);
            $assigned[] = $referral;
            $code = $operator->operatorCode() ?? '';
            $byOperator[$code] = ($byOperator[$code] ?? 0) + 1;
        }

        // 7. Answer.
        return [
            'status' => 'assigned',
            'assigned' => count($assigned),
            'by_operator' => $byOperator,
        ];
    }

    /**
     * One assignment, in a transaction (05 §5 step 6).
     */
    public function assignOne(
        LeadSource $source,
        LeadEntry $entry,
        User $operator,
        string $strategy,
        ?string $note,
        ?int $assignedBy,
    ): CrmReferral {
        return DB::transaction(function () use ($source, $entry, $operator, $strategy, $note, $assignedBy) {
            $contact = $entry->contact;
            $exten = $operator->operatorCode();
            if ($exten === null) {
                throw new RuntimeException('Operator has no extension');
            }

            $leadType = $source->resolveLeadType($entry->payload) ?? 'lead_from_admin';

            /** @var CrmReferral $referral */
            $referral = CrmReferral::create([
                'name' => mb_substr((string) ($entry->name ?? $contact->name ?? ''), 0, 100) ?: null,
                'family' => $contact->family,
                'reagent_code' => $exten,
                'phone' => $contact->phone,
                'lead_type' => $leadType,
                'status' => CrmReferral::STATUS_OPEN,
                'description' => $note !== null ? mb_substr($note, 0, 100) : null,
                'referred_by' => $assignedBy,
                'source_id' => $source->id,
                'entry_id' => $entry->id,
                'referred_at' => now(),
            ]);

            LeadAssignment::create([
                'contact_id' => $contact->id,
                'source_id' => $source->id,
                'entry_id' => $entry->id,
                'operator_id' => $operator->id,
                'operator_code' => $exten,
                'crm_lead_type' => $leadType,
                'strategy' => $strategy,
                'note' => $note,
                'assigned_by' => $assignedBy,
                'crm_referral_id' => $referral->id,
            ]);

            $contact->fill([
                'owner_operator_id' => $operator->id,
                'owner_code' => $exten,
                'owner_assigned_at' => now(),
            ])->save();

            // 6. Freeze the engagement context (complete for live assignments).
            $this->engagement->captureReferralContext($referral, $source, $entry);

            return $referral;
        });
    }

    /**
     * Copy a referral written by a legacy page (trackReferral, 05 §5):
     * find-or-create the contact, derive the source from lead_type,
     * record the assignment, update the owner, context = inferred.
     */
    public function trackReferral(CrmReferral $referral): ?LeadAssignment
    {
        if (LeadAssignment::query()->where('crm_referral_id', $referral->id)->exists()) {
            return null; // already tracked
        }

        $contact = LeadContact::query()->where('phone', $referral->phone)->first()
            ?? LeadContact::create([
                'phone' => $referral->phone,
                'name' => $referral->name,
                'family' => $referral->family,
                'first_seen_at' => $referral->referred_at ?? now(),
                'last_seen_at' => $referral->referred_at ?? now(),
            ]);

        $operator = User::query()->where('exten', $referral->reagent_code)->first();

        $assignment = LeadAssignment::create([
            'contact_id' => $contact->id,
            'source_id' => $referral->source_id,
            'operator_id' => $operator?->id,
            'operator_code' => $referral->reagent_code,
            'crm_lead_type' => $referral->lead_type,
            'strategy' => null,
            'note' => $referral->description,
            'assigned_by' => $referral->referred_by,
            'crm_referral_id' => $referral->id,
        ]);

        $contact->fill([
            'owner_operator_id' => $operator?->id,
            'owner_code' => $referral->reagent_code,
            'owner_assigned_at' => $referral->referred_at ?? now(),
        ])->save();

        $this->engagement->rebuildReferralContext($referral, $contact);

        return $assignment;
    }

    /** Reminder written by an operator → lead_event snooze (05 §9). */
    public function trackSnooze(LeadContact|int $contact, User|int|null $operator, Carbon $until): LeadEvent
    {
        $contact = $contact instanceof LeadContact ? $contact : LeadContact::query()->findOrFail($contact);
        $operatorModel = $operator instanceof User ? $operator : ($operator !== null ? User::query()->find($operator) : null);

        return LeadEvent::create([
            'contact_id' => $contact->id,
            'type' => LeadEvent::TYPE_SNOOZE,
            'operator_id' => $operatorModel?->id,
            'operator_code' => $operatorModel?->operatorCode(),
            'until_at' => $until,
        ]);
    }

    /**
     * «عدم خرید» (close as lost) with the ownership check the legacy
     * code was missing (11 #5): only the assigned operator or an admin
     * may close a referral.
     */
    public function reject(int|CrmReferral $referral, string $course, string $reason, ?User $operator = null): CrmReferral
    {
        $referral = $referral instanceof CrmReferral ? $referral : CrmReferral::query()->findOrFail($referral);

        if ($operator !== null && ! $operator->hasAnyRole(['admin', 'super_admin'])) {
            $mine = LeadAssignment::query()
                ->where('crm_referral_id', $referral->id)
                ->where('operator_id', $operator->id)
                ->exists();

            if (! $mine) {
                throw new RuntimeException('This referral is not assigned to you');
            }
        }

        $referral->fill([
            'status' => CrmReferral::STATUS_CLOSED,
            'course' => $course,
            'agent_description' => $reason,
            'closed_at' => now(),
        ])->save();

        $assignment = LeadAssignment::query()->where('crm_referral_id', $referral->id)->first();
        $operatorModel = $operator ?? ($assignment !== null ? User::query()->find($assignment->operator_id) : null);

        LeadEvent::create([
            'contact_id' => (int) (LeadContact::query()->where('phone', $referral->phone)->value('id') ?? 0),
            'type' => LeadEvent::TYPE_REJECT,
            'operator_id' => $operatorModel?->id,
            'operator_code' => $operatorModel?->operatorCode() ?? $referral->reagent_code,
            'ref_id' => $referral->id,
        ]);

        return $referral;
    }

    /**
     * Phones assigned within the duplicate window (05 §5 step 4).
     *
     * @param  list<string>  $phones
     * @return list<array<string, mixed>>
     */
    private function findDuplicates(array $phones): array
    {
        $since = now()->subDays($this->duplicateWindowDays);

        return LeadAssignment::query()
            ->whereIn('contact_id', function ($query) use ($phones) {
                $query->from('lead_contacts')
                    ->select('id')
                    ->whereIn('phone', $phones);
            })
            ->where('created_at', '>=', $since)
            ->with(['contact', 'operator'])
            ->get()
            ->map(static fn (LeadAssignment $a) => [
                'phone' => $a->contact?->phone,
                'name' => $a->contact?->name,
                'operator' => $a->operator?->name,
                'assigned_at' => $a->created_at?->toDateTimeString(),
            ])
            ->all();
    }
}
