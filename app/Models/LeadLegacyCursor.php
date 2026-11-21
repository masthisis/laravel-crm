<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Progress of the legacy copy tool (spec 05 §1 `lead_legacy_cursor`). */
class LeadLegacyCursor extends Model
{
    protected $fillable = ['adapter', 'last_origin_id', 'copied', 'finished_at'];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
        ];
    }
}
