<?php

namespace App\Http\Controllers\Api\v1\Permission;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Course\CourseResource;
use App\Http\Resources\Api\v1\Permission\PositionResource;
use App\Models\Course\Course;
use App\Models\Permission\Position;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PositionController extends ApiController
{
    /**
     * Display all positions.
     */
    public function index()
    {
        if (DB::table('positions')->count() > 0) {
            $page = [];
            $positions = Position::withTrashed()->with('department', 'parent')->get();
            foreach ($positions as $position) {
                $page[] = [
                    'position' => $position,
                    'childNumber' => DB::table('positions')->where('position_id', $position->id)->count(),
                ];
            }
            return $this->successResponse(PositionResource::collection($page), 200);
        }
        return $this->errorResponse('position-notFound', 404);
    }

    /**
     * Store new position.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title_en' => 'required',
            'title_fa' => 'required',
            "level" => 'required',
            'dept_id' => 'required',
            'position_id' => 'nullable'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if (!$this->checkExistsPositionByInfo(null, $request->title_en, $request->title_fa, $request->dept_id)) {
            $position = new Position();
            $position->title_en = $request->title_en;
            $position->title_fa = $request->title_fa;
            $position->level = $request->level;
            $position->position_id = $request->position_id;
            $position->dept_id = $request->dept_id;
            return ($position->save()) ?
                $this->successResponse(new PositionResource($position), 200, 'position-successfully-saved') :
                $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('Position-exists', 400);
    }

    /**
     * Display the specified position.
     */
    public function show(string $id)
    {
        if ($this->checkExistsPositionById($id)) {
            $position = Position::with('department', 'parent', 'children', 'users', 'roles', 'permissions')->where('id', $id)->first();
            return $this->successResponse(new PositionResource($position), 200);
        }
        return $this->errorResponse('position-notFound', 404);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validator = Validator::make($request->all(), [
            'title_en' => 'nullable',
            'title_fa' => 'nullable',
            'level' => 'nullable',
            'dept_id' => 'nullable',
            'position_id' => 'nullable'
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsPositionById($id)) {
            if (!$this->checkExistsPositionByInfo($id, $request->title_en, $request->title_fa, $request->dept_id)) {
                $position = Position::withTrashed()->where('id', $id)->first();
                if ($request->title_en) $position->title_en = $request->title_en;
                if ($request->title_fa) $position->title_fa = $request->title_fa;
                if ($request->level) $position->level = $request->level;
                if ($request->dept_id) $position->dept_id = $request->dept_id;
                if ($request->position_id) $position->position_id = $request->position_id;
                return ($position->save()) ?
                    $this->successResponse(new PositionResource($position), 200, 'position-successfully-updated') :
                    $this->errorResponse('save-failed', 500);
            }
            return $this->errorResponse('position-exists', 400);
        }
        return $this->errorResponse('position-notFound', 404);
    }

    /**
     * Remove the specified position.
     */
    public function destroy(string $id)
    {
        if ($this->checkExistsPositionById($id)) {
            if (DB::table('positions')->where('id', $id)->whereNull('deleted_at')->exists()) {
                if (Position::where('id', $id)->delete())
                    return $this->successResponse('', 200, 'position-deleted');
                return $this->errorResponse('delete-failed', 500);
            }
            return $this->errorResponse('already-deleted', 400);
        }
        return $this->errorResponse('position-notFound', 404);
    }

    /**
     * Restore the specified position.
     */
    public function restore(string $id)
    {
        if ($this->checkExistsPositionById($id)) {
            if (DB::table('positions')->where('id', $id)->whereNotNull('deleted_at')->exists()) {
                if (Position::withTrashed()->where('id', $id)->restore())
                    return $this->successResponse('', 200, 'position-restored');
                return $this->errorResponse('restore-failed', 500);
            }
            return $this->errorResponse('already-restored', 400);
        }
        return $this->errorResponse('position-notFound', 404);
    }

    public function search(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "title_en" => 'nullable',
                "title_fa" => 'nullable',
            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            $query = Position::select("*");
            if ($request->title_en != null) $query->where("title_en", "like", "%".$request->title_en."%");
            if ($request->title_fa != null) $query->where("title_fa", "like", "%".$request->title_fa."%");

            return $query->exists() ?
                $this->successResponse(PositionResource::collection($query->get()), 200, 'position-found') :
                $this->errorResponse('position-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }

    private function checkExistsPositionById($id)
    {
        return DB::table('positions')->where('id', $id)->exists();
    }

    private function checkExistsPositionByInfo($id, $title_en, $title_fa, $dept_id)
    {
        if ($title_en == null || $title_fa == null || $dept_id == null) return false;

        $result = DB::table('positions')->where(['title_en' => $title_en, 'title_fa' => $title_fa, 'dept_id' => $dept_id]);
        if ($id != null) $result->where('id', '<>', $id);
        return $result->exists();
    }
}
