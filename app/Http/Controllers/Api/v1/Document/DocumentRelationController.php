<?php

namespace App\Http\Controllers\Api\v1\Document;

use App\Http\Controllers\Api\v1\ApiController;
use App\Traits\v1\PivotActions;

class DocumentRelationController extends ApiController
{
    use PivotActions;

    /* ------------------------------| roles |------------------------------ */
    /**
     * Attach a user to department.
     */
    public function attachRoleToDocument($doc_id, $role_id)
    {
        return $this->attach('documents', 'roles', 'doc_role', 'doc_id', 'role_id', $doc_id, $role_id);
    }

    /**
     * Detach a user from department.
     */
    public function detachRoleFromDocument($doc_id, $role_id)
    {
        return $this->detach('documents', 'roles', 'doc_role', 'doc_id', 'role_id', $doc_id, $role_id);
    }

    /**
     * Sync users to department.
     */
    public function syncRolesToDocument($doc_id, $role_ids)
    {
        return $this->sync('documents', 'roles', 'doc_role', 'doc_id', 'role_id', $doc_id, $role_ids);
    }

    /* ------------------------------| permissions |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachPermissionToDocument($doc_id, $permission_id)
    {
        return $this->attach('documents', 'permissions', 'doc_permission', 'doc_id', 'permission_id', $doc_id, $permission_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachPermissionFromDocument($doc_id, $permission_id)
    {
        return $this->detach('documents', 'permissions', 'doc_permission', 'doc_id', 'permission_id', $doc_id, $permission_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncPermissionsToDocument($doc_id, $permission_ids)
    {
        return $this->sync('documents', 'permissions', 'doc_permission', 'doc_id', 'permission_id', $doc_id, $permission_ids);
    }

    /* ------------------------------| departments |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachDepartmentToDocument($doc_id, $department_id)
    {
        return $this->attach('documents', 'departments', 'department_doc', 'doc_id', 'dept_id', $doc_id, $department_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachDepartmentFromDocument($doc_id, $department_id)
    {
        return $this->detach('documents', 'departments', 'department_doc', 'doc_id', 'dept_id', $doc_id, $department_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncDepartmentsToDocument($doc_id, $department_ids)
    {
        return $this->sync('documents', 'departments', 'department_doc', 'doc_id', 'dept_id', $doc_id, $department_ids);
    }
}
