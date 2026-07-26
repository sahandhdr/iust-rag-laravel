<?php

namespace App\Http\Controllers\Api\v1\Log;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\v1\Log\LogResource;
use App\Models\Log\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LogController extends ApiController
{
    public function index()
    {
        if (DB::table('audit_logs')->count() > 0)
        {
            $logs = Log::all();
            return $this->successResponse(LogResource::collection($logs), 200);
        }
        return $this->errorResponse('no-logs', 404);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required',
            'entity_type' => 'required',
            'entity_id' => 'nullable',
            'details' => 'required',
            'ip_address' => 'required',
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        $log = new Log();
        $log->action = $request->action;
        $log->entity_type = $request->entity_type;
        $log->entity_id = $request->entity_id;
        $log->details = $request->details;
        $log->ip_address = $request->ip_address;
        $log->user_id = Auth::id();
        if ($log->save())
        {
            return $this->successResponse(new LogResource($log), 201);
        }
        return $this->errorResponse('save-failed', 500);
    }

    public function show($id)
    {

        if ($this->checkExistsLogById($id))
        {
            $log = Log::where('id', $id)->first();
            return $this->successResponse(new LogResource($log), 200);
        }
        return $this->errorResponse('log-notFound', 404);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'nullable',
            'entity_type' => 'nullable',
            'entity_id' => 'nullable',
            'details' => 'nullable',
            'ip_address' => 'nullable',
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsLogById($id))
        {
            $log = Log::where('id', $id)->first();
            if ($request->action) $log->action = $request->action;
            if ($request->entity_type) $log->entity_type = $request->entity_type;
            if ($request->entity_id) $log->entity_id = $request->entity_id;
            if ($request->details) $log->details = $request->details;
            if ($request->ip_address) $log->ip_address = $request->ip_address;
            if ($log->save())
                return $this->successResponse(new LogResource($log), 200);
            return $this->errorResponse('save-failed', 500);
        }
        return $this->errorResponse('log-notFound', 404);
    }

    public function delete($id)
    {
        if ($this->checkExistsLogById($id))
        {
            if (DB::table('audit_logs')->where('id', $id)->delete())
                return $this->successResponse('', 200, 'delete-successful');
            return $this->errorResponse('delete-failed', 500);
        }
        return $this->errorResponse('log-notFound', 404);
    }

    public function checkExistsLogById($id)
    {
        return DB::table('logs')->where('id', $id)->exists();
    }
}
