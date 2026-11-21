<?php

namespace App\Models;

use App\Support\Roles;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $agent_leads_count
 * @property int $won_leads_count
 * @property float|null $commissions_earned
 * @property string|null $exten
 * @property bool $may_receive_referrals
 * @property bool $is_commission_manager
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'exten',
        'may_receive_referrals',
        'is_commission_manager',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'may_receive_referrals' => 'boolean',
            'is_commission_manager' => 'boolean',
        ];
    }

    /**
     * Panel access is driven by roles:
     * admin panel -> admin / super_admin, agent panel -> sales_staff / super_admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $roleAllowed = match ($panel->getId()) {
            'admin' => $this->hasAnyRole(['admin', 'super_admin']),
            'agent' => $this->hasAnyRole(['sales_staff', 'super_admin']),
            default => false,
        };

        // is_active defaults to true in the database; in-memory models
        // created without the flag (factories, User::create) carry null,
        // which counts as active — only an explicit false blocks access.
        return $roleAllowed && $this->is_active !== false;
    }

    /**
     * Active sales agents, alphabetical — the single source of truth
     * for every agent select (assignment, import, reassignment).
     *
     * @param  Builder<User>  $query
     */
    public function scopeActiveAgents(Builder $query): Builder
    {
        return $query->role(Roles::SALES_STAFF)->where('is_active', true)->orderBy('name');
    }

    /**
     * Eligible referral operators (spec 05 §5 step 1): enabled, may
     * receive referrals and has a PBX extension. The trimmed exten is
     * the operator code.
     *
     * @param  Builder<User>  $query
     */
    public function scopeEligibleOperators(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('may_receive_referrals', true)
            ->whereNotNull('exten')
            ->where('exten', '!=', '')
            ->orderBy('name');
    }

    /** The operator code = trimmed extension. */
    public function operatorCode(): ?string
    {
        $exten = trim((string) ($this->exten ?? ''));

        return $exten === '' ? null : $exten;
    }

    /** @return HasMany<CrmReferral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(CrmReferral::class, 'referred_by');
    }

    /** @return HasMany<Lead, $this> */
    public function agentLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_agent_id');
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'agent_id');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'agent_id');
    }
}
