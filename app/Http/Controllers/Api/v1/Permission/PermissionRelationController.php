<?php

namespace App\Http\Controllers\Api\v1\Permission;

use App\Http\Controllers\Api\v1\ApiController;
use App\Traits\v1\PivotActions;

class PermissionRelationController extends ApiController
{
    use PivotActions;

    /* ------------------------------| roles |------------------------------ */
    /**
     * Attach a role to permission.
     */
    public function attachRoleToPermission($permission_id, $role_id)
    {
        return $this->attach('permissions', 'roles', 'role_permission', 'permission_id', 'role_id', $permission_id, $role_id);
    }

    /**
     * Detach a role from permission.
     */
    public function detahcRoleFromPermission($permission_id, $role_id)
    {
        return $this->detach('permissions', 'roles', 'role_permission', 'permission_id', 'role_id', $permission_id, $role_id);
    }

    /**
     * Sync roles to permission
     */
    public function syncRolesToPermission($permission_id, $role_ids)
    {
        return $this->sync('permissions', 'roles', 'role_permission', 'permission_id', 'role_id', $permission_id, $role_ids);
    }

    /* ------------------------------| position |------------------------------ */
    /**
     * Attach a position to permission.
     */
    public function attachPositionToPermission($permission_id, $position_id)
    {
        return $this->attach('permissions', 'positions', 'permission_position', 'permission_id', 'position_id', $permission_id, $position_id);
    }

    /**
     * Detach a position from permission.
     */
    public function detachPositionFromPermission($permission_id, $position_id)
    {
        return $this->detach('permissions', 'positions', 'permission_position', 'permission_id', 'position_id', $permission_id, $position_id);
    }

    /**
     * Sync positions to permission
     */
    public function syncPositionsToPermission($permission_id, $position_ids)
    {
        return $this->sync('permissions', 'positions', 'permission_position', 'permission_id', 'position_id', $permission_id, $position_ids);
    }

    /* ------------------------------| document |------------------------------ */
    /**
     * Attach a position to permission.
     */
    public function attachDocumentToPermission($permission_id, $doc_id)
    {
        return $this->attach('permissions', 'documents', 'doc_permission', 'permission_id', 'doc_id', $permission_id, $doc_id);
    }

    /**
     * Detach a position from permission.
     */
    public function detachDocumentFromPermission($permission_id, $doc_id)
    {
        return $this->detach('permissions', 'documents', 'doc_permission', 'permission_id', 'doc_id', $permission_id, $doc_id);
    }

    /**
     * Sync positions to permission
     */
    public function syncDocumentToPermission($permission_id, $doc_ids)
    {
        return $this->sync('permissions', 'documents', 'doc_permission', 'permission_id', 'doc_id', $permission_id, $doc_ids);
    }
}
