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

    /* ------------------------------| news |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachNewsToDepartment($department_id, $news_id)
    {
        return $this->attach('departments', 'news', 'dept_news', 'dept_id', 'news_id', $department_id, $news_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachNewsFromDepartment($department_id, $news_id)
    {
        return $this->detach('departments', 'news', 'dept_news', 'dept_id', 'news_id', $department_id, $news_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncNewsToDepartment($department_id, $news_ids)
    {
        return $this->sync('departments', 'news', 'dept_news', 'dept_id', 'news_id', $department_id, $news_ids);
    }

    /* ------------------------------| posts |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachPostToDepartment($department_id, $post_id)
    {
        return $this->attach('departments', 'posts', 'dept_post', 'dept_id', 'post_id', $department_id, $post_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachPostFromDepartment($department_id, $post_id)
    {
        return $this->detach('departments', 'posts', 'dept_post', 'dept_id', 'post_id', $department_id, $post_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncPostsToDepartment($department_id, $post_ids)
    {
        return $this->sync('departments', 'posts', 'dept_post', 'dept_id', 'post_id', $department_id, $post_ids);
    }


    /* ------------------------------| researches |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachResearchToDepartment($department_id, $research_id)
    {
        return $this->attach('departments', 'researches', 'dept_research', 'dept_id', 'research_id', $department_id, $research_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachResearchFromDepartment($department_id, $research_id)
    {
        return $this->detach('departments', 'researches', 'dept_research', 'dept_id', 'research_id', $department_id, $research_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncResearchesToDepartment($department_id, $research_ids)
    {
        return $this->sync('departments', 'researches', 'dept_research', 'dept_id', 'research_id', $department_id, $research_ids);
    }

    /* ------------------------------| webinars |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachWebinarToDepartment($department_id, $webinar_id)
    {
        return $this->attach('departments', 'webinars', 'webinar_dept', 'dept_id', 'webinar_id', $department_id, $webinar_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachWebinarFromDepartment($department_id, $webinar_id)
    {
        return $this->detach('departments', 'webinars', 'webinar_dept', 'dept_id', 'webinar_id', $department_id, $webinar_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncWebinarsToDepartment($department_id, $webinar_ids)
    {
        return $this->sync('departments', 'webinars', 'webinar_dept', 'dept_id', 'webinar_id', $department_id, $webinar_ids);
    }

    /* ------------------------------| courses |------------------------------ */
    /**
     * Attach a task to department.
     */
    public function attachCourseToDepartment($department_id, $course_id)
    {
        return $this->attach('departments', 'courses', 'course_dept', 'dept_id', 'course_id', $department_id, $course_id);
    }

    /**
     * Detach a task from department.
     */
    public function detachCourseFromDepartment($department_id, $course_id)
    {
        return $this->detach('departments', 'courses', 'course_dept', 'dept_id', 'course_id', $department_id, $course_id);
    }

    /**
     * Sync tasks to department.
     */
    public function syncCoursesToDepartment($department_id, $course_ids)
    {
        return $this->sync('departments', 'courses', 'course_dept', 'dept_id', 'course_id', $department_id, $course_ids);
    }
}
