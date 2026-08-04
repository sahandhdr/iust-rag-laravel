<?php

namespace App\Http\Controllers\Api\v1\Department;

use App\Http\Controllers\Api\v1\ApiController;
use App\Traits\v1\PivotActions;

class DepartmentRelationController extends ApiController
{
    use PivotActions;

    /* ------------------------------| users |------------------------------ */
    /**
     * Attach a user to department.
     */
    public function attachUserToDepartment($department_id, $user_id)
    {
        return $this->attach('departments', 'users', 'dept_user', 'dept_id', 'user_id', $department_id, $user_id);
    }

    /**
     * Detach a user from department.
     */
    public function detachUserFromDepartment($department_id, $user_id)
    {
        return $this->detach('departments', 'users', 'dept_user', 'dept_id', 'user_id', $department_id, $user_id);
    }

    /**
     * Sync users to department.
     */
    public function syncUsersToDepartment($department_id, $user_ids)
    {
        return $this->sync('departments', 'users', 'dept_user', 'dept_id', 'user_id', $department_id, $user_ids);
    }

    /* ------------------------------| documents |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachDocumentToDepartment($department_id, $doc_id)
    {
        return $this->attach('departments', 'documents', 'department_doc', 'dept_id', 'doc_id', $department_id, $doc_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachDocumentFromDepartment($department_id, $doc_id)
    {
        return $this->detach('departments', 'documents', 'department_doc', 'dept_id', 'doc_id', $department_id, $doc_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncDocumentsToDepartment($department_id, $doc_ids)
    {
        return $this->sync('departments', 'documents', 'department_doc', 'dept_id', 'doc_id', $department_id, $doc_ids);
    }
}
