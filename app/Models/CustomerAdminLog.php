<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Audit trail of every panel write on a customer (07 §4). */
class CustomerAdminLog extends Model
{
    protected $table = 'customer_admin_log';

    protected $fillable = ['admin_id', 'customer_id', 'action', 'details', 'ip'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
