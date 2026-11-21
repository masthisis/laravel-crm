<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Invoice line (the spec's rayan_prefactor_sub). */
class CrmInvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'crm_invoice_id',
        'product_id',
        'product_name',
        'price',
        'discount',
        'product_count',
    ];

    /** @return BelongsTo<CrmInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CrmInvoice::class, 'crm_invoice_id');
    }
}
