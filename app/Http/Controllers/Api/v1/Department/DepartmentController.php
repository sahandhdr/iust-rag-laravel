<?php

namespace App\Http\Controllers\Api\v1\Department;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Department\DepartmentResource;
use App\Models\Department\Department;
use App\Traits\v1\ApiInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DepartmentController extends ApiController
{
    use ApiInfo;
    /**
     * Display all departments.
     */
    public function index()
    {
        if (DB::table('departments')->count() > 0)
        {
            if (Auth::check())
            {
                $user = Auth::user();
                if ($user->hasRole('admin'))
                {
                    $departments = Department::withTrashed()->get();
                }
                elseif ($user->hasRole('user'))
                {
                    $departments = Department::whereNull('deleted_at')->get();
                }
            }
            return $this->successResponse(DepartmentResource::collection($departments), 200);
        }
        return $this->errorResponse('department-notFound', 404);
    }

    public function getAllDepartments()
    {
        if (DB::table('departments')->count()>0)
        {
            $departments = Department::all();
            return $this->successResponse(DepartmentResource::collection($departments), 200);
        }
        return $this->errorResponse('department-notFound', 404);
    }

    /**
     * Store new department.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'required',
            'name_fa' => 'required',
            'dept_id' => 'nullable'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if (!$this->checkExistsDepartmentByInfo(null, $request->name_en, $request->name_fa)) {
            $department = new Department();
            $department->name_en = $request->name_en;
            $department->name_fa = $request->name_fa;
            $department->dept_id = $request->dept_id;

            return ($department->save()) ?
                $this->successResponse(new DepartmentResource($department), 200, 'department-successfully-saved') :
                $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('department-exists', 400);
    }

    /**
     * Display the specified department.
     */
    public function show(string $id)
    {
        if ($this->checkExistsDepartmentById($id))
        {
            if (Auth::check())
            {
                $user = Auth::user();
                if ($user->hasRole('admin'))
                {
                    $department = Department::withTrashed()->with('children',
                        'users',
                        'news')->where('id', $id)->first();
                }
                elseif ($user->hasRole('user'))
                {
                    $department = Department::where('id', $id)->with('children',
                        'users')->whereNull('deleted_at')->first();
                }
            }
            return $this->successResponse(new DepartmentResource($department), 200);
        }
        return $this->errorResponse('department-notFound', 404);
    }

    /**
     * Update the specified department.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'name_en' => 'nullable',
            'name_fa' => 'nullable',
            'dept_id' => 'nullable'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsDepartmentById($id)) {
            if (!$this->checkExistsDepartmentByInfo($id, $request->name_en, $request->name_fa)) {
                $department = Department::where('id', $id)->whereNull('deleted_at')->first();
                if ($request->name_en) $department->name_en = $request->name_en;
                if ($request->name_fa) $department->name_fa = $request->name_fa;
                if ($request->dept_id) $department->dept_id = $request->dept_id;

                return ($department->save()) ?
                    $this->successResponse(new DepartmentResource($department), 200, 'department-successfully-updated') :
                    $this->errorResponse('save-failed', 500);
            }
            return $this->errorResponse('department-exists', 400);
        }
        return $this->errorResponse('department-notFound', 404);
    }

    /**
     * Remove the specified department.
     */
    public function destroy(string $id)
    {
        if ($this->checkExistsDepartmentById($id)) {
            if (DB::table('departments')->where('id', $id)->whereNull('deleted_at')->exists()) {
                $department = Department::where('id', $id)->first();
                if ($department->delete())
                {
                    $this->deleteChildren($department);
//                    Department::where('dept_id', $id)->with('children')->delete();
                    return $this->successResponse('', 200, 'department-deleted');
                }
                return $this->errorResponse('delete-failed', 500);
            }
            return $this->errorResponse('already-deleted', 400);
        }
        return $this->errorResponse('department-notFound', 404);
    }

    /**
     * Restore the specified department.
     */
    public function restore(string $id)
    {
        if ($this->checkExistsDepartmentById($id)) {
            if (DB::table('departments')->where('id', $id)->whereNotNull('deleted_at')->exists()) {
                $department = Department::withTrashed()->where('id', $id)->first();
                if ($department->restore())
                {
                    $this->restoreParent($department);
                    $this->restoreChildren($department);

//                    Department::withTrashed()->where('dept_id', $id)->restore();
                    return $this->successResponse('', 200, 'department-restored');
                }
                return $this->errorResponse('restore-failed', 500);
            }
            return $this->errorResponse('already-restored', 400);
        }
        return $this->errorResponse('department-notFound', 404);
    }

    public function search(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "name_en" => 'nullable',
                "name_fa" => 'nullable'
            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            if (Auth::check()) {
                $user = Auth::user();
                if ($user->hasRole('super_admin') || $user->hasRole('admin') || $user->hasRole('developer')) {
                    $query = Department::with('children', 'users')->whereNull('deleted_at')->select("*");
                } elseif ($user->hasRole('user')) {
                    $query = Department::with('children', 'users')->whereNull('deleted_at')->select("*");
                }
            }

            if ($request->name_en != null) $query->where("name_en", "like", "%".$request->name_en."%");
            if ($request->name_fa != null) $query->where("name_fa", "like", "%".$request->name_fa."%");

            return $query->exists() ?
                $this->successResponse(DepartmentResource::collection($query->get()), 200, 'department-found') :
                $this->errorResponse('department-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }

    private function checkExistsDepartmentById($id)
    {
        return Department::where('id', $id)->exists();
    }

    private function checkExistsDepartmentByInfo($id, $name_en, $name_fa)
    {
        if ($name_en == null || $name_fa == null) return false;

        $result = DB::table('departments')->where(['name_en' => $name_en, 'name_fa' => $name_fa]);
        if ($id != null) $result->where('id', '<>', $id);
        return $result->exists();
    }

    protected function deleteChildren($department)
    {
        foreach ($department->children as $child) {
            // بازگشتی حذف کردن فرزندان
            $this->deleteChildren($child);

            // حذف فرزند
            $child->delete();
        }
    }

    protected function restoreParent($department)
    {
        foreach ($department->parent()->withTrashed()->get() as $parent) {
            // بازگشتی حذف کردن فرزندان
            $this->restoreParent($parent);

            // حذف فرزند
            $parent->restore();
        }

    }

    protected function restoreChildren($department)
    {
        // برای هر فرزند حذف‌شده رکورد فعلی
        foreach ($department->children()->withTrashed()->get() as $child) {
            // بازگردانی رکورد فرزند
            $child->restore();

            // فراخوانی بازگشتی برای بازگرداندن فرزندان این رکورد
            $this->restoreChildren($child);
        }

    }

    public function test()
    {
        $user_id = 1;
        return $this->getAclInfo($user_id);
    }
}
