<?php

namespace Database\Seeders;

use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'leads.view', 'leads.create', 'leads.update', 'leads.delete',
            'leads.import', 'leads.assign',
            'sources.manage',
            'commissions.view', 'commissions.manage',
            'customers.view', 'customers.manage',
            'products.manage', 'orders.manage', 'subscriptions.manage',
            'users.manage', 'analytics.view',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $admin = Role::findOrCreate(Roles::ADMIN, 'web');
        $admin->givePermissionTo(Permission::all());

        $sales = Role::findOrCreate(Roles::SALES_STAFF, 'web');
        $sales->givePermissionTo([
            'leads.view',
            'commissions.view',
            'customers.view',
        ]);

        Role::findOrCreate(Roles::SUPER_ADMIN, 'web');
    }
}
