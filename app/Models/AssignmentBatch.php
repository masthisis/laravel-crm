<?php

namespace App\Models;

use App\Enums\AssignmentStrategy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentBatch extends Model
{
    protected $fillable = [
        'filename',
        'total_rows',
        'imported_count',
        'duplicates_count',
        'failed_count',
        'total_leads',
        'strategy',
        'assigned_agent_id',
        'user_id',
        'distribution',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'strategy' => AssignmentStrategy::class,
            'total_leads' => 'integer',
            'total_rows' => 'integer',
            'imported_count' => 'integer',
            'duplicates_count' => 'integer',
            'failed_count' => 'integer',
            'distribution' => 'array',
            'errors' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
