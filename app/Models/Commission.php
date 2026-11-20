<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'lead_id',
        'agent_id',
        'commission_rule_id',
        'original_source_id',
        're_engaged_source_id',
        'sold_source_id',
        'base_amount',
        'rate',
        'amount',
        'status',
        'sold_at',
        'approved_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommissionStatus::class,
            'base_amount' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'sold_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
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
