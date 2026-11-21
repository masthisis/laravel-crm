<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * lead_event: CRM follow-ups — snooze (after a reminder) and reject
 * (عدم خرید) (spec 05 §9).
 */
class LeadEvent extends Model
{
    public const TYPE_SNOOZE = 'snooze';

    public const TYPE_REJECT = 'reject';

    protected $fillable = [
        'contact_id',
        'type',
        'operator_id',
        'operator_code',
        'ref_id',
        'until_at',
    ];

    protected function casts(): array
    {
        return [
            'until_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LeadContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'contact_id');
    }

    /** @return BelongsTo<CrmReferral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(CrmReferral::class, 'ref_id');
    }
}
