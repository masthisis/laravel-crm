<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An indexed filter value of an entry (spec 05 §1 `lead_entry_facet`). */
class LeadEntryFacet extends Model
{
    public $timestamps = false;

    protected $fillable = ['entry_id', 'source_id', 'field', 'value'];

    /** @return BelongsTo<LeadEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(LeadEntry::class, 'entry_id');
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }
}
