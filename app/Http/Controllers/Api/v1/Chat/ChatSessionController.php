<?php

namespace App\Http\Controllers\Api\v1\Chat;

use App\Http\Controllers\Api\v1\ApiController;
use App\Http\Resources\Api\v1\Chat\ChatSessionResource;
use App\Models\Chat\ChatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChatSessionController extends ApiController
{
//    return all sessions
    public function index()
    {
        if (DB::table('chat_sessions')->count()>0)
        {
            if (Auth::user()->hasAnyRole(['admin', 'developer']))
            {
                $sessions = ChatSession::all();
                return $this->successResponse(ChatSessionResource::collection($sessions), 200);
            }
            return $this->errorResponse('not-authorized', 403);
        }
        return $this->errorResponse('no-session', 404);
    }

    // return all user's sessions
    public function indexByUser()
    {
        if (DB::table('chat_sessions')->count()>0)
        {
            $userSession = ChatSession::where('user_id',  Auth::id())->get();
            if ($userSession->count() > 0)
                return $this->successResponse(ChatSessionResource::collection($userSession), 200);
            return $this->errorResponse('no-userSession', 404);
        }
        return $this->errorResponse('no-session', 404);
    }

    public function show($id)
    {
        if ($this->checkExistsSessionById($id))
        {

            if (Auth::user()->hasAnyRole(['admin', 'developer']))
            {
                $session = ChatSession::where(['id' => $id])->with('user', 'chat_messages')->first();

                if (!$session)
                    return $this->errorResponse('session-notFound', 404);

                return $this->successResponse(new ChatSessionResource($session), 200);
            }
            else
            {
                $session = ChatSession::where(['id' => $id, 'user_id' => Auth::id()])->with('user', 'chat_messages')->first();

                if (!$session)
                    return $this->errorResponse('session-notFound', 404);

                return $this->successResponse(new ChatSessionResource($session), 200);
            }
        }
        return $this->errorResponse('session-notFound', 404);
    }

    public function destroy($id)
    {
        if ($this->checkExistsSessionById($id))
        {
            if (Auth::user()->hasAnyRole(['admin', 'developer']))
            {
                if (ChatSession::where(['id' => $id])->delete())
                    return $this->successResponse('', 200, 'delete-successful');
                return $this->errorResponse('delete-failed', 500);
            }
            else
            {
                if (ChatSession::where(['id' => $id, 'user_id' => Auth::id()])->delete())
                    return $this->successResponse('', 200, 'delete-successful');
                return $this->errorResponse('delete-failed', 500);
            }
        }
        return $this->errorResponse('session-notFound', 404);
    }

    public function updateTitle(Request $request, $session_id)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required',
        ]);

        if ($validator->fails())
            return $this->errorResponse($validator->messages(), 422);

        if ($this->checkExistsSessionById($session_id))
        {
            $session = ChatSession::where(['id' => $session_id, 'user_id' => Auth::id()])->first();

            if (!$session)
                return $this->errorResponse('session-notFound', 404);

            if ($request->title) $session->title = $request->title;
            if ($session->save())
                return $this->successResponse('', 200, 'update-successful');
            return $this->errorResponse('update-failed', 500);
        }
        return $this->errorResponse('session-notFound', 404);
    }

    public function search(Request $request)
    {
        if ($request->hasHeader("accept") && $request->header("accept") == "application/json" && $request->ajax())
        {
            $validator = Validator::make($request->all(), [
                "title" => 'nullable',

            ]);
            if ($validator->fails())
                return  response()->json(["status" => "validation-error", "errors" => $validator->errors()]);

            $query = ChatSession::where('user_id', Auth::id())->with('chat_messages')->select("*");
            if ($request->title != null) $query->where("title", "like", "%".$request->title."%");

            return $query->exists() ?
                $this->successResponse(ChatSessionResource::collection($query->get()), 200, 'session-found') :
                $this->errorResponse('session-notFound', 404);
        }
        return  $this->errorResponse('refused', 500);
    }

    private function checkExistsSessionById($session_id)
    {
        return DB::table('chat_sessions')->where('id', $session_id)->exists();
    }
}
