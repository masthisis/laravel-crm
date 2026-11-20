<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\Roles;

class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::everyRole());
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole(Roles::everyRole());
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasAnyRole(Roles::backOffice());
    }
}
