<?php

namespace App\Models;

use App\Enums\SourceType;
use Database\Factories\LeadSourceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead source tab (spec 05 §2): a system source, a custom source or a
 * saved segment. The driver decides where rows come from, crm_lead_type
 * is what a referral from this source writes, and the badge + commission
 * rate are per-source too.
 *
 * @property array<int, array<string, mixed>>|null $crm_lead_type_map
 * @property array<string, mixed>|null $config
 */
class LeadSource extends Model
{
    /** @use HasFactory<LeadSourceFactory> */
    use HasFactory;

    public const KIND_SYSTEM = 'system';

    public const KIND_CUSTOM = 'custom';

    public const KIND_SEGMENT = 'segment';

    public const DRIVER_STORED = 'stored';

    public const DRIVER_UTM = 'utm';

    public const DRIVER_ORGANIC = 'organic';

    public const DRIVER_SEGMENT = 'segment';

    public const DRIVER_ALL = 'all';

    public const DRIVER_INVOICES = 'invoices';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'color',
        'description',
        'is_active',
        'kind',
        'driver',
        'crm_lead_type',
        'crm_lead_type_map',
        'config',
        'importable',
        'sort',
        'commission_percent',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'is_active' => 'boolean',
            'importable' => 'boolean',
            'crm_lead_type_map' => 'array',
            'config' => 'array',
            'commission_percent' => 'decimal:2',
        ];
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<LeadEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LeadEntry::class, 'source_id');
    }

    /** @return HasMany<LeadIntegration, $this> */
    public function integrations(): HasMany
    {
        return $this->hasMany(LeadIntegration::class);
    }

    /** Only sources that store their own rows (capture target). */
    /** @param Builder<LeadSource> $query */
    public function scopeStored(Builder $query): Builder
    {
        return $query->where('driver', self::DRIVER_STORED);
    }

    /** Filter-facet fields configured for this source (payload keys). */
    /** @return list<string> */
    public function facetFields(): array
    {
        return array_values((array) ($this->config['facets'] ?? []));
    }

    /** Payload columns configured for a custom source. */
    /** @return list<string> */
    public function payloadColumns(): array
    {
        return array_values((array) ($this->config['columns'] ?? []));
    }

    /**
     * Resolve the CRM lead_type for a row (05 §5 step 2): the source
     * default, unless crm_lead_type_map matches a payload field value.
     */
    public function resolveLeadType(?array $payload = null): ?string
    {
        foreach ((array) ($this->crm_lead_type_map ?? []) as $rule) {
            $field = $rule['field'] ?? null;
            $type = $rule['lead_type'] ?? null;
            if ($field !== null && $type !== null && $payload !== null
                && ($payload[$field] ?? null) === ($rule['value'] ?? null)) {
                return $type;
            }
        }

        return $this->crm_lead_type;
    }

    /** Badge definition {label, color} for lead-type chips (05 §11). */
    public function badge(): array
    {
        return [
            'slug' => $this->crm_lead_type ?? $this->slug,
            'label' => $this->config['badge_label'] ?? $this->name,
            'color' => $this->color,
        ];
    }

    /** Text colour readable on the badge background (04 §3.5). */
    public function badgeTextColor(): string
    {
        $hex = ltrim($this->color ?? '#6366f1', '#');
        if (strlen($hex) !== 6 || ! ctype_digit($hex) && ! ctype_xdigit($hex)) {
            return '#fff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#222' : '#fff';
    }
}
