<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One lead event: a submission, an import row or a copied legacy row
 * (spec 05 §1 `lead_entry`). UNIQUE(source_id, origin_ref) makes every
 * capture path idempotent.
 */
class LeadEntry extends Model
{
    protected $fillable = [
        'source_id',
        'contact_id',
        'origin_ref',
        'name',
        'payload',
        'captured_at',
        'import_batch_id',
        'raw_phone',
        'returning',
        'invalid_phone',
        'invalid_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'captured_at' => 'datetime',
            'returning' => 'boolean',
            'invalid_phone' => 'boolean',
        ];
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    /** @return BelongsTo<LeadContact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(LeadContact::class, 'contact_id');
    }

    /** @return HasMany<LeadEntryFacet, $this> */
    public function facets(): HasMany
    {
        return $this->hasMany(LeadEntryFacet::class, 'entry_id');
    }

    /** Idempotency lookup. */
    /** @param Builder<LeadEntry> $query */
    public function scopeForOrigin(Builder $query, int|string $sourceId, int|string|null $originRef): Builder
    {
        return $query->where('source_id', $sourceId)->where('origin_ref', $originRef === null ? null : (string) $originRef);
    }
}
