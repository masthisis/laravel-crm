<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'original_source_id',
        're_engaged_source_id',
        'sold_source_id',
        'rate',
        'priority',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function originalSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'original_source_id');
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function reEngagedSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 're_engaged_source_id');
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function soldSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'sold_source_id');
    }
}
