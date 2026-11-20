<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Support\Roles;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::everyRole());
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->hasAnyRole(Roles::backOffice())) {
            return true;
        }

        return $lead->assigned_agent_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }
}
