<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One browser × visit × link record (spec 08 §2). Identification is
 * first-identity-wins: only unassigned rows are updated.
 *
 * @property int|null $user_id
 * @property string|null $identification_type
 * @property string|null $phone
 * @property Carbon|null $created_at
 */
class UtmRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'link_id', 'visitor_token_hash', 'visit_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'display_name', 'landing_path', 'referrer', 'created_at', 'last_seen_at',
        'user_id', 'identification_type', 'identified_at', 'phone', 'contact_origin', 'contact_ref',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'identified_at' => 'datetime',
        ];
    }
}
