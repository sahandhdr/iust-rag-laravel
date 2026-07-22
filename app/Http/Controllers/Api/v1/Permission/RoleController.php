<?php

namespace App\Http\Controllers\Api\v1\Permission;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Permission\RoleResource;
use App\Models\Permission\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoleController extends ApiController
{
    /**
     * Display all roles.
     */
    public function index()
    {
        if (DB::table('roles')->count() > 0) {
            $page = [];
            $roles = Role::withTrashed()->get();
            foreach ($roles as $role) {
                $page[] = [
                    'role' => $role,
                    'permissionNumber' => DB::table('role_permission')->where('role_id', $role->id)->count()
                ];
            }
            return $this->successResponse(RoleResource::collection($page), 200);
        }
        return $this->errorResponse('role-notFound', 404);
    }

    /**
     * Store new role.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required',
            'name_fa' => 'required'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if (!$this->checkExistsRoleByInfo(null, $request->name_en, $request->name_fa)) {
            $Role = new Role();
            $Role->name_en = $request->name_en;
            $Role->name_fa = $request->name_fa;
            return ($Role->save()) ?
                $this->successResponse(new RoleResource($Role), 200, 'role-successfully-created') :
                $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('role-exists', 400);
    }

    /**
     * Display the specified role.
     */
    public function show(string $id)
    {
        if ($this->checkExistsRoleById($id)) {
            $Role = Role::withTrashed()->with('permissions', 'positions', 'users')->where('id', $id)->first();
            return $this->successResponse(new RoleResource($Role), 200);
        }
        return $this->errorResponse('role-notFound', 404);
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required',
            'name_fa' => 'required'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsRoleById($id)) {
            if (!$this->checkExistsRoleByInfo($id, $request->name_en, $request->name_fa)) {
                $Role = Role::withTrashed()->where('id', $id)->first();
                if ($request->name_en) $Role->name_en = $request->name_en;
                if ($request->name_fa) $Role->name_fa = $request->name_fa;
                return ($Role->save()) ?
                    $this->successResponse(new RoleResource($Role), 200, 'role-successfully-updated') :
                    $this->errorResponse('save-failed', 500);
            }
            return $this->errorResponse('role-exists', 400);
        }
        return $this->errorResponse('role-notFound', 404);
    }

    /**
     * Remove the specified role.
     */
    public function destroy(string $id)
    {
        if ($this->checkExistsRoleById($id)) {
            if (DB::table('roles')->where('id', $id)->whereNull('deleted_at')->exists()) {
                if (Role::where('id', $id)->delete())
                    return $this->successResponse('', 200, 'role-deleted');
                return $this->errorResponse('delete-failed', 500);
            }
            return $this->errorResponse('already-deleted', 400);
        }
        return $this->errorResponse('role-notFound', 404);
    }

    /**
     * Restore the specified role.
     */
    public function restore(string $id)
    {
        if ($this->checkExistsRoleById($id)) {
            if (DB::table('roles')->where('id', $id)->whereNotNull('deleted_at')->exists()) {
                if (Role::withTrashed()->where('id', $id)->restore())
                    return $this->successResponse('', 200, 'role-restored');
                return $this->errorResponse('restore-failed', 500);
            }
            return $this->errorResponse('already-restored', 400);
        }
        return $this->errorResponse('role-notFound', 404);
    }

    private function checkExistsRoleById($id)
    {
        return DB::table('roles')->where('id', $id)->exists();
    }

    private function checkExistsRoleByInfo($id, $name_en, $name_fa)
    {
        if ($name_en == null || $name_fa == null) return false;

        $result = DB::table('roles')->where(['name_en' => $name_en, 'name_fa' => $name_fa]);
        if ($id != null) $result->where('id', '<>', $id);
        return $result->exists();
    }
}
