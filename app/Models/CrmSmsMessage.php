<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every SMS the CRM sends, with the computed part count (04 §3.5). */
class CrmSmsMessage extends Model
{
    protected $fillable = [
        'phone', 'operator_id', 'referral_id', 'template', 'body', 'parts',
        'status', 'provider_reference',
    ];

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    /**
     * SMS part count: Persian (any non-ASCII char) 1 part up to 70 chars
     * then ceil(len/67); Latin 1 part up to 160 then ceil(len/153).
     */
    public static function partsFor(string $body): int
    {
        $len = mb_strlen($body);
        $persian = preg_match('/[^\x00-\x7F]/u', $body) === 1;

        if ($persian) {
            return $len <= 70 ? 1 : (int) ceil($len / 67);
        }

        return $len <= 160 ? 1 : (int) ceil($len / 153);
    }
}
