<?php

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return [

    /*
    |-----------------------------------------------------------------
    | spatie/laravel-permission configuration
    | Roles: super_admin, admin (back office) and sales_staff (agents).
    |-----------------------------------------------------------------
    */

    'teams' => false,

    'testing' => true,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'pivot_role' => 'role_id',
        'pivot_permission' => 'permission_id',
        'model_morph_key' => 'model_id',
    ],

    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'wildcard_permission' => false,

];
