<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * lead_referral_context: engagement tags frozen at referral time
 * (spec 05 §8) — first/latest engagement and the referral trigger.
 */
class LeadReferralContext extends Model
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INFERRED = 'inferred';

    public const STATUS_UNKNOWN = 'unknown';

    public $timestamps = false;

    protected $fillable = [
        'crm_referral_id',
        'contact_id',
        'first_label',
        'first_at',
        'first_ref',
        'latest_label',
        'latest_at',
        'latest_ref',
        'trigger_label',
        'trigger_at',
        'trigger_ref',
        'status',
        'referred_at',
        'snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'first_at' => 'datetime',
            'latest_at' => 'datetime',
            'trigger_at' => 'datetime',
            'referred_at' => 'datetime',
            'snapshot_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CrmReferral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(CrmReferral::class, 'crm_referral_id');
    }

    /** @return BelongsTo<LeadContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'contact_id');
    }
}
