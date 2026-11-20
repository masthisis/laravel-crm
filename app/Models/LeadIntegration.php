<?php

namespace App\Models;

use App\Enums\IntegrationDriver;
use Database\Factories\LeadIntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadIntegration extends Model
{
    /** @use HasFactory<LeadIntegrationFactory> */
    use HasFactory;

    protected $fillable = [
        'lead_source_id',
        'driver',
        'token',
        'description',
        'config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'driver' => IntegrationDriver::class,
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }
}
