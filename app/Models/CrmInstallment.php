<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** An installment of a manual order (rayan_installment).
 *
 * @property Carbon|null $due_date
 */
class CrmInstallment extends Model
{
    public const STATUS_UNPAID = 0;

    public const STATUS_PAID = 1;

    protected $fillable = [
        'customer_id', 'product_id', 'product_title', 'amount',
        'due_date', 'status', 'tracking_code', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    /** Overdue = unpaid with a due date in the past (05 §4 installment). */
    public function isOverdue(): bool
    {
        return (int) $this->status === self::STATUS_UNPAID
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    /** Unpaid and past due (05 §4 installment criterion). */
    /** @param Builder<self> $query */
    public function scopeOverdue($query): mixed
    {
        return $query->where('status', self::STATUS_UNPAID)->where('due_date', '<', now()->toDateString());
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
