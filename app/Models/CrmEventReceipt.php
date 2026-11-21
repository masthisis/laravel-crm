<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Dedupe receipt for the /api/events receiver (spec 03 §1). */
class CrmEventReceipt extends Model
{
    protected $fillable = ['event_id', 'type', 'idempotency_key', 'result'];
}
