<?php

namespace App\Http\Controllers\Api\v1\Permission;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Permission\PermissionResource;
use App\Models\Permission\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PermissionController extends ApiController
{
    /**
     * Display all permissions.
     */
    public function index()
    {
        if (DB::table('permissions')->count() > 0) {
            $permissions = Permission::withTrashed()->get();
            return $this->successResponse(PermissionResource::collection($permissions), 200);
        }
        return $this->errorResponse('permission-notFound', 404);
    }

    /**
     * Store new permission.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required',
            'name_fa' => 'required'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if (!$this->checkExistsPermissionByInfo(null, $request->name_en, $request->name_fa)) {
            $permission = new Permission();
            $permission->name_en = $request->name_en;
            $permission->name_fa = $request->name_fa;
            return ($permission->save()) ?
                $this->successResponse(new PermissionResource($permission), 200, 'permission-successfully-saved') :
                $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('permission-exists', 400);
    }

    /**
     * Display the specified permission.
     */
    public function show(string $id)
    {
        if ($this->checkExistsPermissionById($id)) {
            $permission = Permission::withTrashed()->where('id', $id)->first();
            return $this->successResponse(new PermissionResource($permission), 200);
        }
        return $this->errorResponse('permission-notFound', 404);
    }

    /**
     * Update the specified permission.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required',
            'name_fa' => 'required'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistspermissionById($id)) {
            if (!$this->checkExistsPermissionByInfo($id, $request->name_en, $request->name_fa)) {
                $permission = Permission::withTrashed()->where('id', $id)->first();
                if ($request->name_en) $permission->name_en = $request->name_en;
                if ($request->name_fa) $permission->name_fa = $request->name_fa;
                return ($permission->save()) ?
                    $this->successResponse(new PermissionResource($permission), 200, 'permission-successfully-updated') :
                    $this->errorResponse('save-failed', 500);
            }
            return $this->errorResponse('permission-exists', 400);
        }
        return $this->errorResponse('permission-notFound', 404);
    }

    /**
     * Remove the specified permission.
     */
    public function destroy(string $id)
    {
        if ($this->checkExistsPermissionById($id)) {
            if (DB::table('permissions')->where('id', $id)->whereNull('deleted_at')->exists()) {
                if (Permission::where('id', $id)->delete())
                    return $this->successResponse('', 200, 'permission-deleted');
                return $this->errorResponse('delete-failed', 500);
            }
            return $this->errorResponse('already-deleted', 400);
        }
        return $this->errorResponse('permission-notFound', 404);
    }

    /**
     * Restore the specified permission.
     */
    public function restore(string $id)
    {
        if ($this->checkExistsPermissionById($id)) {
            if (DB::table('permissions')->where('id', $id)->whereNotNull('deleted_at')->exists()) {
                if (Permission::withTrashed()->where('id', $id)->restore())
                    return $this->successResponse('', 200, 'permission-restored');
                return $this->errorResponse('restore-failed', 500);
            }
            return $this->errorResponse('already-restored', 400);
        }
        return $this->errorResponse('permission-notFound', 404);
    }

    private function checkExistsPermissionById($id)
    {
        return DB::table('permissions')->where('id', $id)->exists();
    }

    private function checkExistsPermissionByInfo($id, $name_en, $name_fa)
    {
        if ($name_en == null || $name_fa == null) return false;

        $result = DB::table('permissions')->where(['name_en' => $name_en, 'name_fa' => $name_fa]);
        if ($id != null) $result->where('id', '<>', $id);
        return $result->exists();
    }
}
