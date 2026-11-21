<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note message or reply (crm ticket sub). */
class CrmNoteReply extends Model
{
    public $timestamps = false;

    protected $fillable = ['crm_note_id', 'operator_id', 'message', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<CrmNote, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(CrmNote::class, 'crm_note_id');
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
