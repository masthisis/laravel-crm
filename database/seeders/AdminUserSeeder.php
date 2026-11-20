<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'admin@example.com')->exists()) {
            return;
        }

        $admin = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => 'ChangeMe!2024',
        ]);

        $admin->assignRole(Roles::SUPER_ADMIN);

        $agent = User::create([
            'name' => 'Sales Agent',
            'email' => 'agent@example.com',
            'password' => 'ChangeMe!2024',
        ]);

        $agent->assignRole(Roles::SALES_STAFF);
    }
}
