<?php

namespace App\Policies;

use App\Models\Commission;
use App\Models\User;
use App\Support\Roles;

class CommissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::everyRole());
    }

    public function view(User $user, Commission $commission): bool
    {
        if ($user->hasAnyRole(Roles::backOffice())) {
            return true;
        }

        return $commission->agent_id === $user->id;
    }

    public function update(User $user, Commission $commission): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }
}
