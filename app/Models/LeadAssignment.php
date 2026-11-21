<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * lead_assignment: assignment history — every ارجاع, including copied
 * legacy rows (spec 05 §1). The conversion report is built from this.
 */
class LeadAssignment extends Model
{
    public const STRATEGY_SINGLE = 'single';

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    protected $fillable = [
        'contact_id',
        'source_id',
        'entry_id',
        'operator_id',
        'operator_code',
        'crm_lead_type',
        'strategy',
        'note',
        'assigned_by',
        'crm_referral_id',
    ];

    /** @return BelongsTo<LeadContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'contact_id');
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return BelongsTo<CrmReferral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(CrmReferral::class, 'crm_referral_id');
    }

    /** @return BelongsTo<LeadEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(LeadEntry::class, 'entry_id');
    }
}
