<?php

namespace App\Models;

use App\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'source_id',
        're_engaged_source_id',
        'sold_source_id',
        'status',
        'estimated_value',
        'sold_amount',
        'assigned_agent_id',
        'customer_id',
        'assigned_at',
        'first_contacted_at',
        'follow_up_at',
        're_engaged_at',
        'sold_at',
        'lost_at',
        'lost_reason',
        'notes',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'estimated_value' => 'decimal:2',
            'sold_amount' => 'decimal:2',
            'assigned_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'follow_up_at' => 'datetime',
            're_engaged_at' => 'datetime',
            'sold_at' => 'datetime',
            'lost_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<LeadSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
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

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<LeadActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at');
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /** Open (not won, not lost) leads. @param Builder<Lead> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            LeadStatus::New->value,
            LeadStatus::Contacted->value,
            LeadStatus::Qualified->value,
            LeadStatus::ReEngaged->value,
        ]);
    }

    /**
     * Open leads whose scheduled follow-up is due or overdue.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeDueFollowUps(Builder $query): Builder
    {
        return $query->open()->whereNotNull('follow_up_at')->where('follow_up_at', '<=', now());
    }

    /**
     * Open leads with no activity for the given number of days —
     * either no activity at all (and created before the cutoff) or
     * only old activities.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeStale(Builder $query, ?int $days = null): Builder
    {
        $days ??= (int) config('crm.leads.stale_days', 7);

        return $query
            ->open()
            ->where('created_at', '<', now()->subDays($days))
            ->whereDoesntHave('activities', fn (Builder $inner) => $inner
                ->where('occurred_at', '>=', now()->subDays($days)));
    }

    /** Leads assigned to the given agent today. @param Builder<Lead> $query */
    public function scopeAssignedToday(Builder $query, ?int $agentId = null): Builder
    {
        return $query
            ->when($agentId !== null, fn (Builder $inner) => $inner->where('assigned_agent_id', $agentId))
            ->whereDate('assigned_at', today());
    }
}
