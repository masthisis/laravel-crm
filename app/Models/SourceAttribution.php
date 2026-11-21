<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-lead organic attribution (spec 08 §3): one row per consult-article
 * or footer lead, unique per (origin_table, origin_id). A lead whose
 * visit was never recorded gets the channel نامشخص (unknown).
 */
class SourceAttribution extends Model
{
    public const FORM_CONSULT_ARTICLE = 'consult_article';

    public const FORM_FOOTER = 'footer';

    protected $fillable = [
        'origin_table', 'origin_id', 'form', 'phone',
        'event_at', 'event_host', 'event_path',
        'vt_channel', 'vt_source', 'vt_referrer_host', 'vt_host',
        'vt_section', 'vt_category', 'vt_post', 'vt_landing_path', 'vt_at',
        'ft_channel', 'ft_source', 'ft_at',
        'lnd_channel', 'lnd_source', 'lnd_at',
    ];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'vt_at' => 'datetime',
            'ft_at' => 'datetime',
            'lnd_at' => 'datetime',
        ];
    }
}
