<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One file import (spec 05 §1 `lead_import_batch`). */
class LeadImportBatch extends Model
{
    protected $fillable = [
        'source_id',
        'file_name',
        'format',
        'file_hash',
        'total',
        'inserted',
        'returning_leads',
        'duplicates',
        'invalid',
        'errors',
        'admin_id',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
        ];
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }
}
