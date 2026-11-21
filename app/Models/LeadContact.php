<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person keyed by canonical phone (spec 05 §1 `lead_contact`).
 * The owner columns mirror the latest assignment.
 *
 * @property string $phone
 * @property int|null $customer_id
 * @property Carbon|null $customer_checked_at
 * @property string|null $name
 * @property string|null $family
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property int|null $owner_operator_id
 * @property string|null $owner_code
 * @property Carbon|null $owner_assigned_at
 */
class LeadContact extends Model
{
    protected $fillable = [
        'phone',
        'customer_id',
        'customer_checked_at',
        'name',
        'family',
        'first_seen_at',
        'last_seen_at',
        'owner_operator_id',
        'owner_code',
        'owner_assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_checked_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'owner_assigned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function ownerOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_operator_id');
    }

    /** @return HasMany<LeadEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LeadEntry::class, 'contact_id');
    }

    /** @return HasMany<LeadContactSource, $this> */
    public function contactSources(): HasMany
    {
        return $this->hasMany(LeadContactSource::class, 'contact_id');
    }
}
