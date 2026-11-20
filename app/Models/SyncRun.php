<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $fillable = [
        'connection_name',
        'source_name',
        'trigger',
        'dry_run',
        'seen',
        'imported',
        'skipped',
        'failed',
        'last_ref',
        'errors',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'seen' => 'integer',
            'imported' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
