<?php

namespace App\Http\Controllers\Api\v1\Permission;

use App\Http\Controllers\Api\v1\ApiController;
use App\Traits\v1\PivotActions;

class PositionRelationController extends ApiController
{
    use PivotActions;
    /* ------------------------------| permissions |------------------------------ */
    /**
     * Attach a permission to position.
    */
    public function attachPermissionToPosition($position_id, $permission_id)
    {
        return $this->attach('positions', 'permissions', 'permission_position', 'position_id', 'permission_id', $position_id, $permission_id);
    }

    /**
     * Detach a permission from position.
     */
    public function detachPermissionFromPosition($position_id, $permission_id)
    {
        return $this->detach('positions', 'permissions', 'permission_position', 'position_id', 'permission_id', $position_id, $permission_id);
    }

    /**
     * Sync permissions to position.
     */
    public function syncPermissionsToPosition($position_id, $permission_ids)
    {
        return $this->sync('positions', 'permissions', 'permission_position', 'position_id', 'permission_id', $position_id, $permission_ids);
    }

    /* ------------------------------| roles |------------------------------ */
    /**
     * Attach a permission to position.
     */
    public function attachRoleToPosition($position_id, $role_id)
    {
        return $this->attach('positions', 'roles', 'position_role', 'position_id', 'role_id', $position_id, $role_id);
    }

    /**
     * Detach a permission from position.
     */
    public function detachRoleFromPosition($position_id, $role_id)
    {
        return $this->detach('positions', 'roles', 'position_role', 'position_id', 'role_id', $position_id, $role_id);
    }

    /**
     * Sync permissions to position.
     */
    public function syncRolesToPosition($position_id, $role_ids)
    {
        return $this->sync('positions', 'roles', 'position_role', 'position_id', 'role_id', $position_id, $role_ids);
    }
    /* ------------------------------| users |------------------------------ */
    /**
     * Attach a user to position.
     */
    public function attachUserToPosition($position_id, $user_id)
    {
        return $this->attach('positions', 'users', 'position_user', 'position_id', 'user_id', $position_id, $user_id);
    }

    /**
     * Detach a user from position.
     */
    public function detachUserFromPosition($position_id, $user_id)
    {
        return $this->detach('positions', 'users', 'position_user', 'position_id', 'user_id', $position_id, $user_id);
    }

    /**
     * Sync users to position.
     */
    public function syncUsersToPosition($position_id, $user_ids)
    {
        return $this->sync('positions', 'users', 'position_user', 'position_id', 'user_id', $position_id, $user_ids);
    }
}
