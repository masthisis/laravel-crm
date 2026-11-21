<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Per-person, per-source summary (spec 05 §1 `lead_contact_source`).
 *
 * @property int $contact_id
 * @property int $source_id
 * @property Carbon|null $first_at
 * @property Carbon|null $last_at
 * @property int $entries
 */
class LeadContactSource extends Model
{
    public $timestamps = false;

    protected $fillable = ['contact_id', 'source_id', 'first_at', 'last_at', 'entries'];

    protected function casts(): array
    {
        return [
            'first_at' => 'datetime',
            'last_at' => 'datetime',
        ];
    }

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
}
