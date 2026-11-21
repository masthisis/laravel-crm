<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An operator note about a customer (the legacy crm ticket group). */
class CrmNote extends Model
{
    public const STATUS_NOTE = 1;

    public const STATUS_CALL_NOTE = 2;

    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'operator_id', 'subject', 'department_id',
        'category_id', 'status', 'ticket_number', 'created_at', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return HasMany<CrmNoteReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(CrmNoteReply::class, 'crm_note_id');
    }
}
