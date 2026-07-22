<?php

namespace App\Http\Controllers\Api\v1\User;

use App\Http\Controllers\Api\v1\ApiController;
use App\Traits\v1\PivotActions;

class UserRelationController extends ApiController
{
    use PivotActions;

    /* ------------------------------| departments |------------------------------ */
    /**
     * Attach a department to user.
     */
    public function attachDepartmentUserToUser($user_id, $department_id)
    {
        return $this->attach('users', 'departments', 'dept_user', 'user_id', 'dept_id', $user_id, $department_id);
    }

    /**
     * Detach a department from user.
     */
    public function detachDepartmentFromUser($user_id, $department_id)
    {
        return $this->detach('users', 'departments', 'dept_user', 'user_id', 'dept_id', $user_id, $department_id);
    }

    /**
     * Sync departments to user.
     */
    public function syncDepartmentsToUser($user_id, $department_ids)
    {
        return $this->sync('users', 'departments', 'dept_user', 'user_id', 'dept_id', $user_id, $department_ids);
    }

    /* ------------------------------| roles |------------------------------ */
    /**
     * Attach a role to user.
     */
    public function attachRoleUserToUser($user_id, $role_id)
    {
        return $this->attach('users', 'roles', 'role_user', 'user_id', 'role_id', $user_id, $role_id);
    }

    /**
     * Detach a role from user.
     */
    public function detachRoleFromUser($user_id, $role_id)
    {
        return $this->detach('users', 'roles', 'role_user', 'user_id', 'role_id', $user_id, $role_id);
    }

    /**
     * Sync roles to user.
     */
    public function syncRolesToUser($user_id, $role_ids)
    {
        return $this->sync('users', 'roles', 'role_user', 'user_id', 'role_id', $user_id, $role_ids);
    }
}
