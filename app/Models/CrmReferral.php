<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The spec's rayan_lead_referal: one row per assignment of a phone to an
 * operator extension. The operator CRM's work queue reads only this.
 *
 * @property string $phone
 * @property string $reagent_code
 * @property string $status
 * @property Carbon|null $referred_at
 * @property Carbon|null $closed_at
 * @property int|null $source_id
 * @property int|null $entry_id
 */
class CrmReferral extends Model
{
    public const STATUS_OPEN = '1';

    public const STATUS_CLOSED = '2';

    protected $fillable = [
        'name',
        'family',
        'reagent_code',
        'phone',
        'lead_type',
        'status',
        'description',
        'course',
        'agent_description',
        'referred_by',
        'source_id',
        'entry_id',
        'referred_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'referred_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasOne<LeadAssignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(LeadAssignment::class, 'crm_referral_id');
    }

    /** @return HasOne<LeadReferralContext, $this> */
    public function context(): HasOne
    {
        return $this->hasOne(LeadReferralContext::class, 'crm_referral_id');
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /** @return BelongsTo<LeadContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'phone', 'phone');
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
}
