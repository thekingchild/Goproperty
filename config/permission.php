<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Role;
use App\Tenancy\OrganizationTeamResolver;
use Spatie\Permission\Models\Permission;

/*
 * spatie/laravel-permission (ARCHITECTURE.md § 5, DATABASE_SCHEMA.md § 1 and
 * § 8). One set of tables for both guards:
 *
 * - customers (`web` guard): teams on, team = organization. Roles are
 *   assigned per organization (`model_has_roles.organization_id`), and the
 *   team id always comes from OrganizationContext (OrganizationTeamResolver),
 *   so it cannot leak between requests or queued jobs.
 * - staff (`staff` guard): global roles, never tied to an organization
 *   (`organization_id` null on the role and on the assignment).
 *
 * The table names are literal in the migrations (the installer's OwnTables
 * reads them from there), so they cannot be changed here.
 */
return [

    'models' => [
        'permission' => Permission::class,
        'role' => Role::class,
        'team' => Organization::class,
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null, // default 'role_id'
        'permission_pivot_key' => null, // default 'permission_id'
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'organization_id',
    ],

    /*
     * Registers a Gate::before check, so `can('members.manage')` asks the
     * user's roles in the current organization. Abilities that are not a
     * permission name fall through to the policies.
     */
    'register_permission_check_method' => true,

    'register_octane_reset_listener' => false,

    'events_enabled' => false,

    'teams' => true,

    // The team id is OrganizationContext's organization, never set by hand.
    'team_resolver' => OrganizationTeamResolver::class,

    'use_passport_client_credentials' => false,

    'display_permission_in_exception' => false,

    'display_role_in_exception' => false,

    'enable_wildcard_permission' => false,

    'cache' => [
        'expiration_time' => DateInterval::createFromDateString('24 hours'),
        'key' => 'spatie.permission.cache',
        'store' => 'default',
    ],
];
