<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'phone2',
        'phone3',
        'company',
        'country',
        'notes',
        'address',
        'bank_cart',
        'bank_number',
        'bank_shaba',
        'account_status',
        'add_from',
        'is_crm_created',
        'created_by_operator_id',
        'last_login_at',
        'reminder_at',
        'reminder_operator_id',
        'wallet',
        'kyc',
        'blocked',
    ];

    protected function casts(): array
    {
        return [
            'is_crm_created' => 'boolean',
            'blocked' => 'boolean',
            'last_login_at' => 'datetime',
            'reminder_at' => 'datetime',
        ];
    }

    /** @return HasMany<LeadActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class)->latest('occurred_at');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest('sold_at');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class)->latest('started_at');
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<LeadContact, $this> */
    public function leadContacts(): HasMany
    {
        return $this->hasMany(LeadContact::class, 'customer_id');
    }

    /** @return HasMany<CrmNote, $this> */
    public function crmNotes(): HasMany
    {
        return $this->hasMany(CrmNote::class)->orderByDesc('created_at');
    }

    /** @return HasMany<CrmInstallment, $this> */
    public function crmInstallments(): HasMany
    {
        return $this->hasMany(CrmInstallment::class);
    }

    /** @return HasMany<CustomerAdminLog, $this> */
    public function adminLogs(): HasMany
    {
        return $this->hasMany(CustomerAdminLog::class)->latest('id');
    }

    /**
     * Match an account by canonical phone across phone/phone2/phone3
     * (spec 01 §1.1 step 6 — column priority phone → phone2 → phone3).
     */
    public static function findByCanonicalPhone(string $canonical): ?self
    {
        $variants = PhoneNormalizer::variants($canonical);

        foreach (['phone', 'phone2', 'phone3'] as $column) {
            /** @var ?self $hit */
            $hit = static::query()->whereIn($column, $variants)->first();
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }
}
