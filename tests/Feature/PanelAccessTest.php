<?php

use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets sales staff access the agent panel but not the admin panel', function () {
    $agent = User::create(['name' => 'Agent', 'email' => 'agent-test@example.com', 'password' => 'password']);
    $agent->assignRole(Roles::SALES_STAFF);

    expect($agent->canAccessPanel(Filament::getPanel('agent')))->toBeTrue()
        ->and($agent->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('lets admins access the admin panel but not the agent panel', function () {
    $admin = User::create(['name' => 'Admin', 'email' => 'admin-test@example.com', 'password' => 'password']);
    $admin->assignRole(Roles::ADMIN);

    expect($admin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($admin->canAccessPanel(Filament::getPanel('agent')))->toBeFalse();
});

it('lets super admins access both panels', function () {
    $super = User::create(['name' => 'Super', 'email' => 'super-test@example.com', 'password' => 'password']);
    $super->assignRole(Roles::SUPER_ADMIN);

    expect($super->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($super->canAccessPanel(Filament::getPanel('agent')))->toBeTrue();
});
