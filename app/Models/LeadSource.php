<?php

namespace App\Models;

use App\Enums\SourceType;
use Database\Factories\LeadSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSource extends Model
{
    /** @use HasFactory<LeadSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'color',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<LeadIntegration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(LeadIntegration::class);
    }
}
