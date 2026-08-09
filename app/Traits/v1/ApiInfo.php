<?php

namespace App\Traits\v1;

use App\Models\User;

trait ApiInfo
{
    protected function getUserAclInfo1($user_id)
    {
        $user = User::where('id', $user_id)->first();

        // اگر کاربر وجود نداشت
        if (!$user)
            return ['message' => 'user-notFound', 'code' => 404];

        // دریافت نقش‌ها
        $roles = $user->roles()->with('permissions')->select('title_en', 'title_fa')->get()->toArray();

        $permissions = $user->roles()->with('permissions')->get()->pluck('permissions')->flatten()->unique('id');

        $departments = $user->departments()->get()->toArray();

        $dept_ids = $user->departments()->get()->pluck('id')->toArray();

        return [
            'user_id' => $user_id,
            'roles' => $roles,
            'permissions' => $permissions,
            'departments' => $departments,
            'dept_ids' => $dept_ids
        ];
    }

    protected function getUserAclInfo($user_id)
    {
        $user = User::with(['roles.permissions', 'departments'])->where('id', $user_id)->first();

        if (!$user) {
            return ['message' => 'user-notFound', 'code' => 404];
        }

        $roles = $user->roles->pluck('title_en')->filter()->values()->all();

        $permissions = $user->roles
            ->flatMap(fn ($role) => $role->permissions->pluck('title_en'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $departments = $user->departments->pluck('title_en')->filter()->values()->all();
        $dept_ids = $user->departments->pluck('id')->values()->all();

        $username = $user->username ?: ($user->email ?: ('user_' . $user->id));

        return [
            'user_id'     => (int) $user->id,
            'username'    => $username,
            'roles'       => $roles,
            'permissions' => $permissions,
            'departments' => $departments,
            'dept_ids'    => $dept_ids,
        ];
    }

    protected function getUserDeptIds($user_id)
    {
        return $this->getUserAclInfo($user_id)['dept_ids'];
    }

}
