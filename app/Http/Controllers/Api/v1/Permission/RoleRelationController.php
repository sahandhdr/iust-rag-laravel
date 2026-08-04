<?php

namespace App\Http\Controllers\Api\v1\Permission;

use App\Traits\v1\PivotActions;
use App\Http\Controllers\Controller;

class RoleRelationController extends Controller
{
    use PivotActions;

    /* ------------------------------| permissions |------------------------------ */
    /**
     * Attach a permission to role.
     */
    public function attachPermissionToRole($role_id, $permission_id)
    {
        return $this->attach('roles', 'permissions', 'role_permission', 'role_id', 'permission_id', $role_id, $permission_id);
    }

    /**
     * Detach a permission from role.
     */
    public function detachPermissionFromRole($role_id, $permission_id)
    {
        return $this->detach('roles', 'permissions', 'role_permission', 'role_id', 'permission_id', $role_id, $permission_id);
    }

    /**
     * Sync permissions to role.
     */
    public function syncPermissionsToRole($role_id, $permission_ids)
    {
        return $this->sync('roles', 'permissions', 'role_permission', 'role_id', 'permission_id', $role_id, $permission_ids);
    }

    /* ------------------------------| positions |------------------------------ */
    /**
     * Attach a position to role.
     */
    public function attachPositionToRole($role_id, $position_id)
    {
        return $this->attach('roles', 'positions', 'role_position', 'role_id', 'position_id', $role_id, $position_id);
    }

    /**
     * Detach a position from role.
     */
    public function detachPositionFromRole($role_id, $position_id)
    {
        return $this->detach('roles', 'positions', 'role_position', 'role_id', 'position_id', $role_id, $position_id);
    }

    /**
     * Sync positions to role.
     */
    public function syncPositionsToRole($role_id, $position_ids)
    {
        return $this->sync('roles', 'positions', 'role_position', 'role_id', 'position_id', $role_id, $position_ids);
    }

    /* ------------------------------| users |------------------------------ */
    /**
     * Attach a user to role.
     */
    public function attachUserToRole($role_id, $user_id)
    {
        return $this->attach('roles', 'users', 'role_user', 'role_id', 'user_id', $role_id, $user_id);
    }

    /**
     * Detach a user from role.
     */
    public function detachUserFromRole($role_id, $user_id)
    {
        return $this->detach('roles', 'users', 'role_user', 'role_id', 'user_id', $role_id, $user_id);
    }

    /**
     * Sync users to role.
     */
    public function syncUsersToRole($role_id, $user_ids)
    {
        return $this->sync('roles', 'users', 'role_user', 'role_id', 'user_id', $role_id, $user_ids);
    }

    /* ------------------------------| documents |------------------------------ */
    /**
     * Attach a user to role.
     */
    public function attachDocumentToRole($role_id, $doc_id)
    {
        return $this->attach('roles', 'documents', 'doc_role', 'role_id', 'doc_id', $role_id, $doc_id);
    }

    /**
     * Detach a user from role.
     */
    public function detachDocumentFromRole($role_id, $doc_id)
    {
        return $this->detach('roles', 'documents', 'doc_role', 'role_id', 'doc_id', $role_id, $doc_id);
    }

    /**
     * Sync users to role.
     */
    public function syncDocumentsToRole($role_id, $doc_ids)
    {
        return $this->sync('roles', 'documents', 'doc_role', 'role_id', 'doc_id', $role_id, $doc_ids);
    }
}
