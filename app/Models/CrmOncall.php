<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call-queue row uploaded by an admin (rayan_oncalls). */
class CrmOncall extends Model
{
    protected $fillable = ['phone', 'operator_id', 'category', 'status', 'queued_at'];

    protected function casts(): array
    {
        return ['queued_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /** @return BelongsTo<CrmOncallCategory, $this> */
    public function outcome(): BelongsTo
    {
        return $this->belongsTo(CrmOncallCategory::class, 'status');
    }
}
