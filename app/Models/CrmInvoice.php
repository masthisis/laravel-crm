<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice header mirror (the spec's rayan_prefactor_group). Status: 1
 * unpaid, 2 paid, 3 preparing, 4 shipped, 10 rejected, 11 deleted.
 * Status 3 on pay_type 8/9 is a failed market checkout = unpaid (11 #25).
 *
 * @property int $status
 * @property int|null $pay_type
 * @property int $total
 * @property string|null $discount
 * @property string|null $reagent_code
 * @property string|null $phone
 */
class CrmInvoice extends Model
{
    public const STATUS_UNPAID = 1;

    public const STATUS_PAID = 2;

    public const STATUS_PREPARING = 3;

    public const STATUS_SHIPPED = 4;

    public const STATUS_REJECTED = 10;

    public const STATUS_DELETED = 11;

    /** Payment types 8/9 (BazaarPay/Myket) treat status 3 as unpaid. */
    public const FAILED_CHECKOUT_PAY_TYPES = [8, 9];

    /** Products excluded from every CRM sales count (11 #24). */
    public const EXCLUDED_PRODUCT_ID = 22;

    protected $fillable = [
        'legacy_id',
        'customer_id',
        'phone',
        'tracking_code',
        'total',
        'discount',
        'reagent_code',
        'status',
        'pay_type',
        'is_installment',
        'token',
        'comments',
        'invoice_date',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'is_installment' => 'boolean',
            'invoice_date' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return HasMany<CrmInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CrmInvoiceLine::class, 'crm_invoice_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** True paid = status 2 only (commission rule, 09 §2.1). */
    public function isPaid(): bool
    {
        return (int) $this->status === self::STATUS_PAID;
    }

    /** Status 3 with pay_type 8/9 is a failed checkout start. */
    public function isFailedCheckout(): bool
    {
        return (int) $this->status === self::STATUS_PREPARING
            && in_array((int) ($this->pay_type ?? 0), self::FAILED_CHECKOUT_PAY_TYPES, true);
    }

    /**
     * "My sales" rule (04 §3.1 ExtenSalesCond): the referrer code equals
     * the extension, or the discount code ends in the extension after a
     * non-digit (vip107 and 107 match for exten 107, 1107 does not).
     */
    public function belongsToExten(string $exten): bool
    {
        if (! ctype_digit($exten)) {
            return false;
        }

        $reagent = trim((string) $this->reagent_code);
        $discount = trim((string) $this->discount);

        return $reagent === $exten
            || ($discount !== '' && preg_match('/(^|[^0-9])'.preg_quote($exten, '/').'$/u', $discount) === 1);
    }

    /**
     * Conflict detection (04 §3.8): referrer and discount codes point to
     * different operators. Assumes 3-digit extensions per the legacy rule.
     *
     * @return array{mine: bool, d_owner: string}|null null = no conflict
     */
    public function conflictFor(string $exten): ?array
    {
        if (! ctype_digit($exten)) {
            return null;
        }

        $reagent = trim((string) $this->reagent_code);
        $discount = trim((string) $this->discount);

        $dOwner = '';
        if ($discount !== '' && preg_match('/[^0-9]([0-9]{3})$/u', $discount, $m) === 1) {
            $dOwner = $m[1];
        }

        $dIsMine = $discount !== ''
            && preg_match('/(^|[^0-9])'.preg_quote($exten, '/').'$/u', $discount) === 1;

        $conflict = ($reagent === $exten && $dOwner !== '' && ! $dIsMine)
            || ($dIsMine && $reagent !== '' && $reagent !== $exten);

        return $conflict ? ['mine' => $dIsMine, 'd_owner' => $dOwner] : null;
    }

    /** Unpaid for the operator dashboard: statuses 1 and 11 (04 §3.1). */
    /** @param Builder<CrmInvoice> $query */
    public function scopeUnpaidForDashboard(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_UNPAID, self::STATUS_DELETED]);
    }
}
