<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A campaign link (spec 08 §1): immutable, with a public token used as
 * utm_link_id in the URL, or discovered automatically from traffic.
 */
class UtmLink extends Model
{
    protected $fillable = [
        'public_token', 'automatic_key', 'display_name', 'destination_url',
        'destination_identity', 'generated_url', 'utm_source', 'utm_medium',
        'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'origin', 'created_by_admin_id',
    ];
}
